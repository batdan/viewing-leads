<?php
namespace leads;

/**
 * Gestion du Spool de leads par email
 *
 * @author Daniel Gomes
 */
class spoolMail
{
    /**
     * Attributs
     */
    protected $_dbh;                // Instance PDO base 'viewing'
    protected $_dbh_leads;          // Instance PDO base 'leads'

    private $_emailDest;            // Emails destinataires
    private $_emailAdmin;           // Emails d'administration (suivi)
    private $_emailTest;            // Email de test : Les leads contenant l'une de ses adresses ne seront pas envoyés aux destinataires

    protected $_tableCta;           // Tables contenant les leads
    protected $_ctaConf;            // Objet contenant la configuration du Spool de traitement des leads
    protected $_spoolConf;          // Objet contenant la configuration du Spool de traitement des leads

    protected $_result;             // Résultat de l'envoi du lead


    /**
     * Constructeur
     *
     * @param       string      $tableCta           Tables contenant les leads
     * @param       object      $ctaConf            Objet contenant la configuration du CTA / gestion de la table des leads
     * @param       object      $spoolConf          Objet contenant la configuration du Spool de traitement des leads
     */
    public function __construct($tableCta, $ctaConf, $spoolConf)
    {
        // Instance PDO base 'viewing'
        $this->_dbh = \core\dbSingleton::getInstance();

        // Instance PDO base 'leads'
        $this->_dbh_leads = \core\dbSingleton::getInstance('leads');

        // Hydratation de la classe
        $this->_tableCta    = $tableCta;
        $this->_ctaConf     = $ctaConf;
        $this->_spoolConf   = $spoolConf;

        // Stockage des emails de destination, de suivi et de tests
        $this->_emailDest   = $this->emailArray($spoolConf->email_dest);
        $this->_emailAdmin  = $this->emailArray($spoolConf->email_admin);
        $this->_emailTest   = $this->emailArray($spoolConf->email_test);
    }


    /**
     * Lancement du traitement des leads du Spool
     */
    public function run()
    {
        // echo $this->_tableCta . '<br><br>';

        // Traitement des envoies de leads
        $this->sendLead();

        // Traitement des relances pour les leads incomplets
        //$this->sendRecall();
    }


    /**
     * Traitement des envoies de leads
     */
    private function sendLead()
    {
        $tableCta = $this->_tableCta;

        $reqCta = "SELECT *, UNIX_TIMESTAMP(date_crea) AS ts_date_crea FROM $tableCta WHERE (stepLeadStatut = 1 OR stepLeadStatut = 2) AND sendLeadStatut = 0";
        $sqlCta = $this->_dbh_leads->query($reqCta);

        while ($resCta = $sqlCta->fetch()) {

            $this->_result  = array();

            $stepLeadStatut = $resCta->stepLeadStatut;
            $sendLeadStatut = $resCta->sendLeadStatut;

            $tsDateCrea    = $resCta->ts_date_crea;
            $delaiSec       = intval($this->_ctaConf->delai) * 60;

            /**
             * Le lead est complet
             *         ou
             * il est valide et le temps maximum de remplissage est dépassé
             *
             * Le traitement du lead peut alors s'effectuer
             */
            if (
                $stepLeadStatut == 2
                ||
                ($stepLeadStatut == 1 && ($tsDateCrea + $delaiSec) < time())
               )
            {
                // Envoi du lead
                $subject = $this->_spoolConf->lead_mail_subject;
                $message = $this->_spoolConf->lead_mail_message;

                if ($this->_spoolConf->lead_mail_activ == 1) {

                    // Envoi des emails
                    $this->sendMail($resCta, $subject, $message);

                    // Permet de différencier les objets de spool par extension de la classe 'spoolMail'
                    $this->sendLeadCallBack($resCta);

                    // Sauvegarde du résultat de l'envoi du lead dans l'entrée de la table du CTA
                    $this->saveResultInLead($resCta);
                }
            }
        }
    }


    /**
     * Permet de différencier les objets de spool par extension de la classe 'spoolMail'
     *
     * @param       object      $resCta         Informations du leads récupérée dans la table du CTA
     */
    protected function sendLeadCallBack($resCta)
    {

    }


    /**
     * Sauvegarde du résultat de l'envoi du lead dans l'entrée de la table du CTA
     *
     * @param       object      $resCta         Informations du leads récupérée dans la table du CTA
     */
    protected function saveResultInLead($resCta)
    {
        $tableCta = $this->_tableCta;

        $req = "UPDATE      $tableCta
                SET         sendLeadStatut  = :sendLeadStatut,
                            spoolName       = :spoolName,
                            retourWS        = :retourWS
                WHERE       id              = :id";

        $sql = $this->_dbh_leads->prepare($req);
        $sql->execute( array_merge($this->_result, array(':id' => $resCta->id)) );
    }


    /**
     * Envoi des emails
     *
     * @param       object      $resCta         Informations du leads récupérée dans la table du CTA
     * @param       string      $subject        Sujet du mail
     * @param       string      $message        Message du mail
     *
     * @return      integer                     Nombre d'emails envoyés par Swift_Mailer
     */
    private function sendMail($resCta, $subject, $message)
    {
        // Décryptage du mot de passe SMTP
        $crypt  = new \core\crypt();
        $passwd = $crypt->decrypt($this->_spoolConf->smtp_passwd);

        // Connexion comte SMTP avec StartTLS
        $transport = \Swift_SmtpTransport::newInstance( $this->_spoolConf->smtp_server,
                                                        $this->_spoolConf->smtp_port,
                                                        'tls');

        $transport->setUsername($this->_spoolConf->smtp_account);
        $transport->setPassword($passwd);
        $transport->setStreamOptions(array('ssl' => array('allow_self_signed' => true, 'verify_peer' => false)));

        // Instance du Mailer avec la configuration des mails sortants
        $mailer = \Swift_Mailer::newInstance($transport);

        // Création du mail
        $mail   = \Swift_Message::newInstance();

        // Contenu du message
        $message    = $this->emailVarReplace($resCta, $message);

        $imgSignature = $mail->embed(\Swift_Image::fromPath('https://www.dixpix.fr/mail/carte-mail-dg.png'));

        $corpsHtml  = '<html>';
        $corpsHtml .=   '<body>';
        $corpsHtml .=       str_replace('___img_signature___', $imgSignature, $message);
        $corpsHtml .=   '</body>';
        $corpsHtml .= '</html>';

        $corpsTxt   = strip_tags($message);

        // Sujet du message
        $subject    = $this->emailVarReplace($resCta, $subject);

        // Filtre de nos adresses mail pour test
        $emailDest = $this->_emailDest;
        if (in_array( $resCta->email, $this->_emailTest )) {
            $emailDest = array();
        }

        $mail->setSubject($subject);                                                                        // Objet du message
        $mail->setFrom(array($this->_spoolConf->smtp_from => 'Contact ' . $resCta->domain));                // Adresse de d'expéditeur
        $mail->setReplyTo(array($this->_spoolConf->smtp_from));                                             // Adresse de réponse
        $mail->setTo($emailDest);                                                                    // Adresse du destinataire
        $mail->setBcc($this->_emailAdmin);                                                                  // Adresse destinataires en copie

        $mail->setBody($corpsHtml);                                                                         // Corps du message - Version HTML
        $mail->setContentType('text/html');

        $mail->addPart($corpsTxt, 'text/plain');                                                            // Corps du message - Version alternative en texte
        //$mail->attach(Swift_Attachment::fromPath('my-document.pdf'));                                     // Possibilité d'attacher une pièce jointe

        // Envoi du mail
        $resultSendMail = 0;
        $resultSendMail = $mailer->send($mail);

        if ((count($emailDest) + count($this->_emailAdmin)) == $resultSendMail) {
            $this->_result[':sendLeadStatut']    = 1;
            $this->_result[':spoolName']         = 'spoolMail';
            $this->_result[':retourWS']          = '';
        } else {
            $this->_result[':sendLeadStatut']    = -1;
            $this->_result[':spoolName']         = 'spoolMail';
            $this->_result[':retourWS']          = '';
        }

        /* Tests */
        /*
        echo 'id : ' . $resCta->id . '<br>';
        echo 'stepLeadStatut : ' . $resCta->stepLeadStatut . '<br>';
        echo 'sendLeadStatut : ' . $resCta->sendLeadStatut . '<br>';

        $result = 'sendMail failed';
        if ((count($emailDest) + count($this->_emailAdmin)) == $resultSendMail) {
            $result = 'sendMail ok';
        }

        echo 'checkSendMail : ' . $result . '<br>';
        echo 'resultSendMail : ' . $resultSendMail . '<br>';

        echo '<pre>';
        print_r($emailDest);
        print_r($this->_emailAdmin);
        print_r($this->_emailTest);
        echo '</pre><hr>';
        */
    }


    /**
     * Traitement des relances
     */
    private function sendRecall()
    {
        $reqCta = "SELECT * FROM $table WHERE stepLeadStatut = 0 AND sendLeadStatut = 0";
        $sqlCta = $this->_dbh_leads->query($reqCta);

        while ($resCta = $sqlCta->fetch()) {

            echo 'id : ' . $resCta->id . '<br>';
            echo 'stepLeadStatut : ' . $resCta->stepLeadStatut . '<br>';
            echo 'sendLeadStatut : ' . $resCta->sendLeadStatut . '<hr>';
        }
    }


    /**
     * Stockage des tableaux d'email
     *
     * @param       string      $emails         Vide, 1 un email ou plusieurs adresses emails séparées d'un point virgule
     * @return      array
     */
    private function emailArray($emails)
    {
        $emails = str_replace(' ', '', $emails);
        $emails = str_replace(',', ';', $emails);
        $emails = trim($emails);
        $emails = trim($emails, ';');

        if (empty($emails)) {
            return array();
        }

        return explode(';', $emails);
    }


    /**
     * Remplacement des variables d'email
     *
     * @param       object      $resCta         Informations du leads récupérée dans la table du CTA
     * @param       string      $texte          Texte dans lequel les variables seront remplacées
     *
     * @return      string                      Retour du texte avec les variables remplacée
     */
    private function emailVarReplace($resCta, $texte)
    {
        $texte = str_replace('___lead_name___',     $this->_ctaConf->lead_name,     $texte);

        $texte = str_replace('___domain___',        $resCta->domain,                $texte);
        $texte = str_replace('___civilite___',      $resCta->civilite,              $texte);
        $texte = str_replace('___nom___',           $resCta->nom,                   $texte);
        $texte = str_replace('___prenom___',        $resCta->prenom,                $texte);

        if (isset($resCta->montant)) {
            $montant = number_format($resCta->montant, 0, ',', ' ') . ' €';
            $texte = str_replace('___montant___',   $montant,                       $texte);
        }

        if (strstr($texte, '___url_lead___')) {
            $crypt = new \core\crypt('consultation des leads');
            $query = $crypt->encrypt($this->_tableCta . '|' . $resCta->id);

            $urlLead = $this->_spoolConf->url_lead . urlencode($query);
            $texte   = str_replace('___url_lead___', '<a href="' . $urlLead . '">' . $urlLead . '</a>', $texte);
        }

        return $texte;
    }
}
