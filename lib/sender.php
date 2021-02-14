<?php
namespace leads;

/**
 * Gestion des Spool de leads
 *
 * Documentation sur les tables de CTA / Leads :
 * > ownCloud/Pym_Dan/Sites/Tables CTA - leads.odt
 *
 * @author Daniel Gomes
 */
class sender
{
    /**
     * Attributs
     */
    private $_dbh;                  // Instance PDO base 'viewing'
    private $_dbh_leads;            // Instance PDO base 'leads'


    /**
     * Constructeur
     */
    public function __construct()
    {
        // Instance PDO base 'viewing'
        $this->_dbh = \core\dbSingleton::getInstance();

        // Instance PDO base 'leads'
        $this->_dbh_leads = \core\dbSingleton::getInstance('leads');
    }


    public function run()
    {
        // Récupération de la liste des tables de stockage des leads
        $req = "SHOW TABLES WHERE Tables_in_leads LIKE 'cta_%'";
        $sql = $this->_dbh_leads->query($req);

        // Requête preparée : Configuration de traitement des leads
        $reqCnf = "SELECT * FROM lead WHERE table_name = :table_name";
        $sqlCnf = $this->_dbh->prepare($reqCnf);

        // Requête preparée : Récupération des Spool de traitement des leads
        $reqSpool = "SELECT * FROM lead_spool WHERE id = :id";
        $sqlSpool = $this->_dbh->prepare($reqSpool);

        // Boucle sur les tables de leads à traiter
        while ($res = $sql->fetch()) {

            // Nom de la table
            $tableCta = $res->Tables_in_leads;

            // On récupère la configuration du traitement pour cette table
            $sqlCnf->execute( array(':table_name' => $tableCta) );
            if ($sqlCnf->rowCount() > 0) {
                $resCnf = $sqlCnf->fetch();

                // Si la configuration du CTA est active, on traite les leads
                if ($resCnf->activ == 1) {

                    // Informations sur le Spool de traitement des leads
                    $sqlSpool->execute( array(':id' => $resCnf->id_spool) );
                    if ($sqlSpool->rowCount() > 0) {
                        $resSpool = $sqlSpool->fetch();

                        $spoolObject = "\leads\\" . $resSpool->object;

                        $spool = new $spoolObject($tableCta, $resCnf, $resSpool);
                        $spool->run();
                    }
                }
            }
        }
    }
}
