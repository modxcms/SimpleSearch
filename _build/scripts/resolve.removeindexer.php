<?php

use MODX\Revolution\modPlugin;

/** @var array $options */
if ($options[xPDOTransport::PACKAGE_ACTION] === xPDOTransport::ACTION_UPGRADE) {
    /** @var xPDOTransport $transport */
    /** @var modPlugin $plugin */
    $plugin = $transport->xpdo->getObject(modPlugin::class, ['name' => 'SimpleSearchIndexer']);
    if ($plugin) {
        $plugin->remove();
    }
}

return true;