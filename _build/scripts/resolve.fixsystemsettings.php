<?php

use MODX\Revolution\modSystemSetting;

/** @var array $options */
if ($options[xPDOTransport::PACKAGE_ACTION] === xPDOTransport::ACTION_UPGRADE) {
    /** @var xPDOTransport $transport */
    /** @var modSystemSetting $setting */
    $setting = $transport->xpdo->getObject(modSystemSetting::class, ['key' => 'simplesearch.driver_class']);
    if ($setting && $setting->value === 'SimpleSearchDriverBasic') {
        $setting->set('value', '');
        $setting->save();
    }
}

return true;
