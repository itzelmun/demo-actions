<?php
/**
 * Consent Authentication Processing filter
 *
 * Filter for requesting the user to give consent before attributes are
 * released to the SP.
 *
 * @package simpleSAMLphp
 * @version $Id$
 */
class sspmod_consent_Auth_Process_Consent extends SimpleSAML_Auth_ProcessingFilter
{
    /**
     * Button to recive focus
     *
     * @var string|null
     */
    private $_focus = null;

    /**
     * Include attribute values
     *
     * @var bool
     */
    private $_includeValues = false;

    /**
     * Check remember consent
     *
     * @var bool
     */
    private $_checked = false;

    /**
     * Consent backend storage configuration
     *
     * @var array
     */
    private $_store = null;

    /**
     * Attributes where the value should be hidden
     *
     * @var array
     */
    private $_hiddenAttributes = array();

    /**
     * Attributes which should not require consent
     *
     * @var aray
     */
    private $_noconsentattributes = array();

    /**
     * Initialize consent filter
     *
     * Validates and parses the configuration
     *
     * @param array $config   Configuration information
     * @param mixed $reserved For future use
     */
    public function __construct($config, $reserved)
    {
        assert('is_array($config)');
        parent::__construct($config, $reserved);

        if (array_key_exists('includeValues', $config)) {
            if (!is_bool($config['includeValues'])) {
                throw new SimpleSAML_Error_Exception(
                    'Consent: includeValues must be boolean. ' .
                    var_export($config['includeValues']) . ' given.'
                );
            }
            $this->_includeValues = $config['includeValues'];
        }

        if (array_key_exists('checked', $config)) {
            if (!is_bool($config['checked'])) {
                throw new SimpleSAML_Error_Exception(
                    'Consent: checked must be boolean. ' .
                    var_export($config['checked']) . ' given.'
                );
            }
            $this->_checked = $config['checked'];
        }

        if (array_key_exists('focus', $config)) {
            if (!in_array($config['focus'], array('yes', 'no'), true)) {
                throw new SimpleSAML_Error_Exception(
                    'Consent: focus must be a string with values `yes` or `no`. ' .
                    var_export($config['focus']) . ' given.'
                );
            }
            $this->_focus = $config['focus'];
        }

        if (array_key_exists('hiddenAttributes', $config)) {
            if (!is_array($config['hiddenAttributes'])) {
                throw new SimpleSAML_Error_Exception(
                    'Consent: hiddenAttributes must be an array. ' .
                    var_export($config['hiddenAttributes']) . ' given.'
                );
            }
            $this->_hiddenAttributes = $config['hiddenAttributes'];
        }

        if (array_key_exists('noconsentattributes', $config)) {
            if (!is_array($config['noconsentattributes'])) {
                throw new SimpleSAML_Error_Exception(
                    'Consent: noconsentattributes must be an array. ' .
                    var_export($config['noconsentattributes']) . ' given.'
                );
            }
            $this->_noconsentattributes = $config['noconsentattributes'];
        }
        
        if (array_key_exists('store', $config)) {
            try {
                $this->_store = sspmod_consent_Store::parseStoreConfig($config['store']);
            } catch(Exception $e) {
                SimpleSAML_Logger::error(
                    'Consent: Could not create consent storage: ' .
                    $e->getMessage()
                );
            }
        } 
    }

    /**
     * Process a authentication response
     *
     * This function saves the state, and redirects the user to the page where
     * the user can authorize the release of the attributes.
     * If storage is used and the consent has already been given the user is 
     * passed on.
     *
     * @param array &$state The state of the response.
     *
     * @return void
     */
    public function process(&$state)
    {	// print_r($state);
        assert('is_array($state)');
        assert('array_key_exists("UserID", $state)');
        assert('array_key_exists("Destination", $state)');
        assert('array_key_exists("entityid", $state["Destination"])');
        assert('array_key_exists("metadata-set", $state["Destination"])');		
        assert('array_key_exists("entityid", $state["Source"])');
        assert('array_key_exists("metadata-set", $state["Source"])');

        $session  = SimpleSAML_Session::getInstance(); 
        $metadata = SimpleSAML_Metadata_MetaDataStorageHandler::getMetadataHandler();

        /**
         * If the consent module is active on a bridge $state['saml:sp:IdP']
         * will contain an entry id for the remote IdP. If not, then the
         * consent module is active on a local IdP and nothing needs to be
         * done.
         */
        if (isset($state['saml:sp:IdP'])) {
            $idpmeta         = $metadata->getMetaData($state['saml:sp:IdP'], 'saml20-idp-remote');
            $state['Source'] = $idpmeta;
        } elseif ($session->getIdP() !== null) {
            // For backwards compatibility. TODO: Remove in version 1.8
            $idpmeta         = $metadata->getMetaData($session->getIdP(), 'saml20-idp-remote');
            $state['Source'] = $idpmeta;
        }

        if ($this->_store !== null) {
            // Do not use consent if disabled on source entity 
            if ( isset($state['Source']['consent.disable']) && in_array($state['Destination']['entityid'], $state['Source']['consent.disable'])) {
                SimpleSAML_Logger::debug(
                    'Consent: Consent disabled for entity ' .
                    $state['Destination']['entityid']
                );
                return;
            }

            $source      = $state['Source']['metadata-set'] . '|' . $state['Source']['entityid'];
            $destination = $state['Destination']['metadata-set'] . '|' . $state['Destination']['entityid'];
            $attributes  = $state['Attributes'];

            // Remove attributes that do not require consent
            foreach ($attributes AS $attrkey => $attrval) {
                if (in_array($attrkey, $this->_noconsentattributes)) {
                    unset($attributes[$attrkey]);
                }
            }

            SimpleSAML_Logger::debug('Consent: userid: ' . $state['UserID']);
            SimpleSAML_Logger::debug('Consent: source: ' . $source);
            SimpleSAML_Logger::debug('Consent: destination: ' . $destination);

            $userId       = self::getHashedUserID($state['UserID'], $source);
            $targetedId   = self::getTargetedID($state['UserID'], $source, $destination);
            $attributeSet = self::getAttributeHash($attributes, $this->_includeValues);
			
            SimpleSAML_Logger::debug(
                'Consent: hasConsent() [' . $userId . '|' . $targetedId . '|' .
                $attributeSet . ']'
            );
            
            if ($this->_store->hasConsent($userId, $targetedId, $attributeSet)) {
                // Consent already given
                SimpleSAML_Logger::stats('Consent: Consent found');
                return;
            }

            SimpleSAML_Logger::stats('Consent: Consent notfound');

            $state['consent:store']              = $this->_store;
            $state['consent:store.userId']       = $userId;
            $state['consent:store.destination']  = $targetedId;
            $state['consent:store.attributeSet'] = $attributeSet;
        } else {
            SimpleSAML_Logger::stats('Consent: No storage');
        }

        $state['consent:focus']               = $this->_focus;
        $state['consent:checked']             = $this->_checked;
        $state['consent:hiddenAttributes']    = $this->_hiddenAttributes;
        $state['consent:noconsentattributes'] = $this->_noconsentattributes;

        // User interaction nessesary. Throw exception on isPassive request	
        if (isset($state['isPassive']) && $state['isPassive'] == true) {
            throw new SimpleSAML_Error_NoPassive(
                'Unable to give consent on passive request.'
            );
        }

        // Save state and redirect
        $id  = SimpleSAML_Auth_State::saveState($state, 'consent:request');
        $url = SimpleSAML_Module::getModuleURL('consent/getconsent.php');
        SimpleSAML_Utilities::redirect($url, array('StateId' => $id));
    }

    /**
     * Generate a unique identifier of the user
     * 
     * @param string $userid The user id
     * @param string $source The source id
     *
     * @return string SHA1 of the user id, source id and salt 
     */
    public static function getHashedUserID($userid, $source)
    {
        return hash('sha1', $userid . '|' . SimpleSAML_Utilities::getSecretSalt() . '|' . $source);
    }

    /**
     * Generate a unique targeted identifier
     *
     * @param string $userid      The user id
     * @param string $source      The source id
     * @param string $destination The destination id
     *
     * @return string SHA1 of the user id, source id, destination id and salt 
     */
    public static function getTargetedID($userid, $source, $destination)
    {
        return hash('sha1', $userid . '|' . SimpleSAML_Utilities::getSecretSalt() . '|' . $source . '|' . $destination);
    }

    /**
     * Generate unique identitier for attributes
     *
     * Create a hash value for the attributes that changes when attributes are
     * added or removed. If the attribute values are included in the hash, the 
     * hash will change if the values change.
     *
     * @param string $attributes    The attributes
     * @param bool   $includeValues Whether or not to include the attribute
     *                              value in the generation of the hash.
     *
     * @return string SHA1 of the user id, source id, destination id and salt 
     */
    public static function getAttributeHash($attributes, $includeValues = false)
    {
        $hashBase = null;	
        if ($includeValues) {
            ksort($attributes);
            $hashBase = serialize($attributes);
        } else {
            $names = array_keys($attributes);
            sort($names);
            $hashBase = implode('|', $names);
        }
        return hash('sha1', $hashBase);
    }
}
