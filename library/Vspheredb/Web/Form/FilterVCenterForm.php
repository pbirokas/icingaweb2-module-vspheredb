<?php

namespace Icinga\Module\Vspheredb\Web\Form;

use dipl\Html\Form;
use dipl\Translation\TranslationHelper;
use Icinga\Module\Vspheredb\Db;

class FilterVCenterForm extends Form
{
    use TranslationHelper;

    protected $db;

    public function __construct(Db $connection)
    {
        $this->db = $connection->getDbAdapter();
        $this->setMethod('GET');
    }

    public function getHexUuid()
    {
        return $this->getValue('uuid');
    }

    protected function assemble()
    {
        $enum = $this->enumVCenters();
        $this->addElement('uuid', 'select', [
            'options' => $enum,
            'class'   => 'autosubmit',
            'value'   => key($enum),
        ]);
    }

    protected function enumVCenters()
    {
        return $this->db->fetchPairs(
            $this->db->select()->from(
                ['vc' => 'vcenter'],
                [
                    'uuid' => 'LOWER(HEX(vc.instance_uuid))',
                    'host' => 'vcs.host',
                ]
            )->join(
                ['vcs' => 'vcenter_server'],
                'vcs.vcenter_id = vc.id',
                []
            )
        );
    }
}
