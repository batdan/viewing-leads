<?php
namespace leads;

/**
 * Gestion du Spool de leads par webservice JSON
 * En partenariat avec la société Habitat-Trade
 *
 * Site : www.habitat-trade.com
 *
 * @author Daniel Gomes
 */
class spoolHabitatTrade extends spoolMail
{
    /**
     * Constructeur
     *
     * @param       string      $tableCta           Tables contenant les leads
     * @param       object      $ctaConf            Objet contenant la configuration du CTA / gestion de la table des leads
     * @param       object      $spoolConf          Objet contenant la configuration du Spool de traitement des leads
     */
    public function __construct($tableCta, $ctaConf, $spoolConf)
    {
        parent::__construct($tableCta, $ctaConf, $spoolConf);
    }


    /**
     * Permet de différencier les objets de spool par extension de la classe 'spoolMail'
     *
     * @param       object      $resCta             Informations du leads récupérée dans la table du CTA
     */
    protected function sendLeadCallBack($resCta)
    {
        $lead_infos = array(

            // Compte utilisateur
            'user'      => $this->_spoolConf->userWS,           // Obligatoire
            'code'      => $this->_spoolConf->codeWS,           // Obligatoire

            // Secteur d'activité
            'secteur'   => $this->idSecteur(),                  // Obligatoire      tinyint     (4)

            // Civilité
            'civ'       => $this->civCodes($resCta->civilite),  //                  tinyint     (1)
            'nom'       => $resCta->nom,                        // Obligatoire      varchar     (255)
            'prenom'    => $resCta->prenom,                     //                  varchar     (255)

            // Lieu d'habitation
            'zipcode'   => $resCta->cp,                         // Obligatoire      int         (5)
            'ville'     => $resCta->ville,                      // Obligatoire      varchar     (255)

            // Coordonnées
            'email'     => $resCta->email,                      // Obligatoire      varchar     (255)
            'tel'       => $resCta->telephone,                  // Obligatoire      int         (10)
            'tel2'      => $resCta->telephone_2,                //                  int         (10)

            // Commentaires sur la demande
            'comm'      => $resCta->commentaires,               //                  text

            // Situation - informations supplémentaires facultatives
            'situation' => $resCta->situation,                  //                  tinyint     (1)         1 = Propriétaire, 2 = Locataire payeur, 3 = Locataire non payeur
            'logement'  => $resCta->logement,                   //                  tinyint     (1)         1 = Maison, 2 = Appartement
        );

        $post = array('data' => json_encode($lead_infos));

        /*
        echo '<pre>';
        print_r($post);
        echo '</pre>';
        */

        /**
         * Liste des codes retour du webservice Habitat-Trade :
         *
         *      0 	Le traitement s’est correctement déroulé
         *      1 	Sécurité (contrôle du login / password)
         *      2 	Champ manquant
         *      3 	Valeur incorrecte
         *      4 	Lead refusé
         *      5 	Lead en doublon
         *      9 	Erreur interne
         */
        $curlReturn = $this->curlPost($post);

        /**
         * Exemple de retour : ﻿{"code":"0","demande":"2218352","message":"Le traitement sest correctement deroule (2218352)"}
         */

        // Le retour JSON contient du BOM | 1er caractère caché
        if (utf8_encode(mb_substr($curlReturn, 0, 1)) == 'ï»¿') {
            $curlReturn = mb_substr($curlReturn, 1);
        }

        $checkReturn = json_decode($curlReturn);

        if (intval($checkReturn->code) == 0) {
            $this->_result[':sendLeadStatut']   = 1;
            $this->_result[':spoolName']        = 'spoolHabitatTrade';
            $this->_result[':retourWS']         = $curlReturn;

        } else {
            $this->_result[':sendLeadStatut']   = -1;
            $this->_result[':spoolName']        = 'spoolHabitatTrade';
            $this->_result[':retourWS']         = $curlReturn;
        }
    }


    /**
     * Conversion des civilités ne code
     *
     * @param       string      $civiliteTxt        Mme | Melle | M.
     * @return      integer
     */

    private function civCodes($civiliteTxt)
    {
        switch($civiliteTxt)
        {
            case 'M.'    : $civilite = 1;   break;
            case 'Mme'   : $civilite = 2;   break;
            case 'Melle' : $civilite = 3;   break;
            default      : $civilite = '';
        }

        return $civilite;
    }


    /**
     * Methode POST en CURL pour soumettre le formulaire
     *
     * @param       array       $post               Tableau de données à soumettre
     * @return      integer                         Code de retour Habitat-Trade
     */
    private function curlPost($post)
    {
        $ch  = curl_init();
        curl_setopt($ch, CURLOPT_URL,               $this->_spoolConf->url_webservice);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER,    1);
        curl_setopt($ch, CURLOPT_POST,              true);
        curl_setopt($ch, CURLOPT_POSTFIELDS,        $post);

        $res = curl_exec($ch);

        curl_close($ch);

        return $res;
    }


    /**
     * Récupération de l'id habitat-trade du secteur
     */
    private function idSecteur()
    {
        $idSecteur = '';

        $req = "SELECT id_habitat_trade FROM lead_conf WHERE table_cta = :table_cta";
        $sql = $this->_dbh->prepare($req);
        $sql->execute( array( ':table_cta' => $this->_tableCta ));

        if ($sql->rowCount() > 0) {
            $res = $sql->fetch();
            $idSecteur = $res->id_habitat_trade;
        }

        return $idSecteur;
    }
}
