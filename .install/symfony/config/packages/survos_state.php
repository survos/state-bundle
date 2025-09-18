<?php

declare(strict_types=1);

use Survos\StateBundle\Service\ConfigureFromAttributesService;
use Symfony\Config\FrameworkConfig;

return static function (FrameworkConfig $framework) {
//return static function (ContainerConfigurator $containerConfigurator): void {

    if (class_exists(ConfigureFromAttributesService::class))
        foreach ([
//                     \App\Workflow\EuroObjWorkflow::class,
//                     \App\Workflow\InstWorkflow::class,
//                     \App\Workflow\MusdigObjectWorkflow::class,
////                 \App\Workflow\OwnerWorkflow::class,
//                     \App\Workflow\ForteObjWorkflow::class,
//                     \App\Workflow\ImgWorkflow::class,
//                     \App\Workflow\LinkWorkflow::class,
//                     \App\Workflow\ExtractWorkflow::class,
//                     \App\Workflow\RecordWorkflow::class,
//                     \App\Workflow\SourceWorkflow::class,
//                     \App\Workflow\GrpWorkflow::class,
//                     \App\Workflow\GlamWorkflow::class,
//                     \App\Workflow\MuseumObjectWorkflow::class,
//
//                     \App\Workflow\AacWorkflow::class,
//                     \App\Workflow\EuroWorkflow::class,
//                     \App\Workflow\DdbWorkflow::class,
//
//            \App\Workflow\SmithFileWorkflow::class,
//            \App\Workflow\SmithObjWorkflow::class,
//            \App\Workflow\SmithWorkflow::class,
                 ] as $workflowClass) {
            if (class_exists($workflowClass)) {
                ConfigureFromAttributesService::configureFramework($workflowClass, $framework, [$workflowClass]);
            }
        }

};
