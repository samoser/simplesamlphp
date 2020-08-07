<?php

declare(strict_types=1);

namespace SimpleSAML\Module\core\Auth\Process;

use Exception;
use SAML2\Constants;
use SAML2\XML\saml\NameID;
use SimpleSAML\Assert\Assert;
use SimpleSAML\Auth;
use SimpleSAML\Utils;

/**
 * Filter to generate the eduPersonTargetedID attribute.
 *
 * By default, this filter will generate the ID based on the UserID of the current user.
 * This is by default generated from the attribute configured in the config of this authproc-filter.
 * If this configuration isn't present, the userid will be generated from the
 * eduPersonPrincipalName attribute, if it is present.
 *
 * It is possible to generate this attribute from another attribute by specifying this attribute
 * in this configuration.
 *
 * Example - generate from user ID:
 * <code>
 * 'authproc' => array(
 *   50 => 'core:TargetedID',
 * )
 * </code>
 *
 * Example - generate from mail-attribute:
 * <code>
 * 'authproc' => array(
 *   50 => array('class' => 'core:TargetedID' , 'attributename' => 'mail'),
 * ),
 * </code>
 *
 * @author Olav Morken, UNINETT AS.
 * @package SimpleSAMLphp
 */
class TargetedID extends Auth\ProcessingFilter
{
    /**
     * The attribute we should generate the targeted id from, or NULL if we should use the
     * UserID.
     *
     * @var string|null
     */
    private $attribute = null;

    /**
     * Whether the attribute should be generated as a NameID value, or as a simple string.
     *
     * @var boolean
     */
    private $generateNameId = false;

    /**
     * The name of the attribute that holds a unique identifier for the user
     *
     * @var string
     */
    private $identifyingAttribute;

    /**
     * @var \SimpleSAML\Utils\Config|string
     * @psalm-var \SimpleSAML\Utils\Config|class-string
     */
    protected $configUtils = Utils\Config::class;


    /**
     * Initialize this filter.
     *
     * @param array &$config  Configuration information about this filter.
     * @param mixed $reserved  For future use.
     */
    public function __construct(array &$config, $reserved)
    {
        parent::__construct($config, $reserved);

        if (array_key_exists('attributename', $config)) {
            $this->attribute = $config['attributename'];
            if (!is_string($this->attribute)) {
                throw new Exception('Invalid attribute name given to core:TargetedID filter.');
            }
        }

        if (array_key_exists('nameId', $config)) {
            $this->generateNameId = $config['nameId'];
            if (!is_bool($this->generateNameId)) {
                throw new Exception('Invalid value of \'nameId\'-option to core:TargetedID filter.');
            }
        }

        Assert::keyExists($config, 'identifyingAttribute', "Missing mandatory 'identifyingAttribute' config setting.");
        Assert::stringNotEmpty(
            $config['identifyingAttribute'],
            "Consent: 'identifyingAttribute' must be a non-empty string."
        );
        $this->identifyingAttribute = $config['identifyingAttribute'];
    }


    /**
     * Inject the \SimpleSAML\Utils\Config dependency.
     *
     * @param \SimpleSAML\Utils\Config $configUtils
     */
    public function setConfigUtils(Utils\Config $configUtils): void
    {
        $this->configUtils = $configUtils;
    }


    /**
     * Apply filter to add the targeted ID.
     *
     * @param array &$state  The current state.
     * @return void
     */
    public function process(array &$state): void
    {
        Assert::keyExists($state, 'Attributes');

        if ($this->attribute === null) {
            Assert::keyExists($state['Attributes'], $this->identifyingAttribute, "Missing mandatory '%s' attribute.");
            $userID = $state['Attributes'][$this->identifyingAttribute];
        } else {
            if (!array_key_exists($this->attribute, $state['Attributes'])) {
                throw new Exception('core:TargetedID: Missing attribute \'' . $this->attribute .
                    '\', which is needed to generate the targeted ID.');
            }

            $userID = $state['Attributes'][$this->attribute][0];
        }


        $secretSalt = $this->configUtils::getSecretSalt();

        if (array_key_exists('Source', $state)) {
            $srcID = self::getEntityId($state['Source']);
        } else {
            $srcID = '';
        }

        if (array_key_exists('Destination', $state)) {
            $dstID = self::getEntityId($state['Destination']);
        } else {
            $dstID = '';
        }

        $uidData = 'uidhashbase' . $secretSalt;
        $uidData .= strlen($srcID) . ':' . $srcID;
        $uidData .= strlen($dstID) . ':' . $dstID;
        $uidData .= strlen($userID) . ':' . $userID;
        $uidData .= $secretSalt;

        $uid = hash('sha1', $uidData);

        if ($this->generateNameId) {
            // Convert the targeted ID to a SAML 2.0 name identifier element
            $nameId = new NameID();
            $nameId->setValue($uid);
            $nameId->setFormat(Constants::NAMEID_PERSISTENT);

            if (isset($state['Source']['entityid'])) {
                $nameId->setNameQualifier($state['Source']['entityid']);
            }
            if (isset($state['Destination']['entityid'])) {
                $nameId->setSPNameQualifier($state['Destination']['entityid']);
            }
        } else {
            $nameId = $uid;
        }

        $state['Attributes']['eduPersonTargetedID'] = [$nameId];
    }


    /**
     * Generate ID from entity metadata.
     *
     * This function takes in the metadata of an entity, and attempts to generate
     * an unique identifier based on that.
     *
     * @param array $metadata  The metadata of the entity.
     * @return string  The unique identifier for the entity.
     */
    private static function getEntityId(array $metadata): string
    {
        $id = '';

        if (array_key_exists('metadata-set', $metadata)) {
            $set = $metadata['metadata-set'];
            $id .= 'set' . strlen($set) . ':' . $set;
        }

        if (array_key_exists('entityid', $metadata)) {
            $entityid = $metadata['entityid'];
            $id .= 'set' . strlen($entityid) . ':' . $entityid;
        }

        return $id;
    }
}
