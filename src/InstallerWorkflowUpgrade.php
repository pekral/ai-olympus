<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

final class InstallerWorkflowUpgrade
{

    // Source and installed digests of published workflow skills predating the command entry.
    private const DIGESTS = [
        'auto-interactive-testing/SKILL.md' => [
            '12e54dbb8c21d700a1e374574a92f94e32cfbabdbfab46512c39acd8eb556399',
            '537c1573f8cae2138c4030129035d4bae9edbcd73dcc1bd2db4f72ed8ddc88bd',
            '5affc9e44b2a9bb42aec433e8d7d53c333cbe823a5c4a8c84446b4187d33f818',
            '72c94fd0876ff8a84a620255091a095070be50b7b04ce1244cedf8a09863c654',
            '76ae19a58402c3f347b6fb42bd18fa0f6c5655155ec21d01b2d0a5ee79d985ae',
            '79ba242c4934e497cf07fece1224accc03efbd8ffb1b8b346b3a963510c43c22',
            '9e56667772f32051457c687f03fe1aa3f080e118cf46301d6eb64f4959dd096a',
            '9f1704fc88a32fd91a9338516410c71c506110bccfcb63dfe887d1d8028ad596',
        ],
        'code-review-bugsnag/SKILL.md' => [
            '336c8c242f29a1922b190262d089e7e2accb9caebe292f8cd8bd4459e1e0a3f7',
            '5fb09d40c068bf3b0fcb9984f33691107a240098a0cba7e513a039a67545c5d2',
            'a582ba7314548eacb470cf59d27c58003d135bf00b96dac8ebc3dd9320677f64',
            'a6f052085bb822c0d60a30ad09efe14af52581836f00556a6f127507953f339b',
        ],
        'code-review-github/SKILL.md' => [
            '114c20186898a6a5304c000da4ffa7d0d3cdee7e528efc632f08938cdc76b94c',
            '117d1fe31e2dfbce16d55dda7e931b287b33b8fd455ce13c71a09c19ad70be6c',
            '29aebc3fc9e9da5f620ff57105825ccb012012835334274c2b61af7a064065e2',
            '3094af5e99f0d4fd66adcf2fd8adc3ab6b3a6bce6ac315051a2f70beee683bb6',
            '423d7ef3253416b7f9f31fde2993228318dddebb454336536e911f5b89461ada',
            '44502a3c75388fceca464e463b71b76e2aa5d23e28cb6313199b28ec6f02dd1c',
            '481ae09cea9e3150d7b745ddf7c414c0be55be95b832db6fb31b7f8a75cc6fb1',
            '6f8fdbad42c51afe1dec7c44d0a8b18c0d7853bf26646f04ebfd312a3838ff07',
            'eb8c96b2e534a15ca422ee1b71bcd08a784d1fb392d60c464c24c556f93530de',
            'f1f6c66c9200eb599d5bf3703c25423b1c1d1637404fbda6c4626dd1d73a7015',
        ],
        'code-review-jira/SKILL.md' => [
            '0d30242998e6be8e1217b76d2a00ef4dcd85358cd8e5e38d7117f89d9216d9a3',
            '1427025b3f970d13e7de5cccb427ac7d025d5106f93f76ea0d64a5b5fd266357',
            '2ae79f1624d4d17b4e535fdd978e4ad6c043c7ed9a0112afb0000cca6d1cc530',
            '5a1451f06d6dd6e1fe3a1debcf724bd6f97208f23db04df91fea20f039851347',
            '6a6c971bd3756e12f1053863509f1e02c9de5198da6cf1180e909b185d8e8b96',
            '753147826bdb9886a2740a368aae1688800575eba77c1fb4c05afa7576ae85de',
            '95c272ecb08dd63a8b954d25b9275f6ca826873a9441918fd5c6c5805a95e1f7',
            'a4ca5b32abf9e33af876b6a1b10e10f83ff53fd06dcd521808b72b71a7da75a1',
            'b5bb910977b2ecf7af2622af3187f075e59ee418d83c365d2834c0cac965bd98',
            'c311fc0df663c462a6118990adf484e5fe584089f1a94fe44f423cab11fab6eb',
            'db82d264704cc004323d8d65ae19b6508cc329c5fc059d7042f82b79596d39ba',
            'e2b62952c1a0ce06852983893dab2055ce03bc39fb9776f04b00699debc1863b',
        ],
        'deliver-page-redesign/SKILL.md' => [
            '137644500fef79ecda8da7efd424442b5b26624e0d610ea98ccdd165c4eff4c3',
            '3357bac99de74e1a19044a9f49af220fc2d25a334ecaf136efcbbcc955f6bbcf',
            '7e7febf767fa829038da0d04ca82c6e17a1146a34d11426ebe3fc14ae8019944',
            '825ff938a98f206c5cd4a889161b391847699d4609003601b7c37e9a8b44e8ff',
            '87df56576a57dcb25e558f0f3c8ef39a692fe2e6cf38c5630f1f876b840d34f8',
            '9e2c3f96a12ac5045becd6ff74c91e9bf5373f246ae3e2e225648d665405131b',
        ],
        'do-it/SKILL.md' => [
            'a0069229428179cf2ff0a1026e200aeab0435a6a8c2b44378af807d306750a43',
            'bca1e3425e40fe5ed542d73f760fcc888227c62ae9e77d5cb4bd37dca6c0a7bf',
            'e5fa63ab08e854c213888165a53e05291fab8bf853eabb2b5f8995c66ad13907',
            'f34cf92a72fd535c1a18490c1bb2ff6e78982ea1b005db0e1291110c7e0b4720',
        ],
        'github-issue-triage/SKILL.md' => [
            '164abe88fe08b5bed70602195a03afcdd714be33a906644a1149e34cdb0aaf4d',
            '26959b2a1fe70af700697e2f4bd3fb316f3436593b1b60ec2389ad2908bb1e25',
            '3f23479928b42f9bc316df11b375f657ea48b0606acfaad12226ba53c6aaf692',
            '727a5bd445a6b51c65c2d14c35cd6a74d596a452578aed1795cb8b153a38c370',
            '7a2acd51123d76ab7dd8dc4c1cd99a186139ca1b474956134ef584a85ee8e4cd',
            '8a36997640552eb01f262f72bd00216e8d018bc0f19304b7d2b6f271fb62b82d',
            '8e5cb3e9e800dbb8f8a90680d302a6fa6f559f73c7b4ce6ca3555205d21bb7af',
            'a6a70687792bcb178d988baf74a6c167c141e8aaa4d16f3489c9046bfa9fa29f',
            'bd0d52c35728a87aec46133dbbfebb4e29d494b6c8c15241d1509ed35600aa7f',
            'c83830185b4f346146c236e65133b48cfea003394fa435acc1fb912adc8db5bc',
            'e2504dacdf6110b2faf307103d2c0b688a4cc6130aee93ea1a6c20f6a02710af',
            'f2af36c5cf5aed4ae9a15ad38ec4fb8d10485b396c3e02c3aadee46b809f668c',
        ],
        'merge-github-pr/SKILL.md' => [
            '0cd20ece1a5b3bd26702f2006d5d7861bfad8099d7630d2dc084455ae0c581ba',
            '0fb899884d1c21d16522a2b8c3cbe2a2e0d0ecdcf63702b14bdc681cf743bb8c',
            '18e1c16c9318298ae6361ffc029369fcba6058a9562d09ecbae992efdf50229a',
            '1e0c3837f08cc300ce4f230b85cccc86e1d901548265ddf555a3413c116830c2',
            '365aa9cdc116fdf58771b388fa52e282932708fb3a28db9c1625e702c7d28e95',
            '3d0c9aa09701f6ee75ec6544e14d3048fade05485c164af9f65e3488daf1d328',
            '3d62091bef661646235ee0b21443a436793d4db1f029726ca09139542e35d3e4',
            '4118c15a5cf66577344d6ef204d35ff4bf3825caa93a609313ee3a4a1374c456',
            '5c23dabdd2856c8a2260e0cb3b8bd5aedc087eff1cc2f141ae31074353adf3ef',
            '6d8fc212263624b9e8abd19e491434907a4c2bd2c6098f6d15a683170ae4f3ba',
            '80648432cdd83dc44c02ebb691bc52cdbf5804c4488f01e41ffede9c8692510d',
            '8709912c992330fcabb3276bd66e1e901d3b4875624f7830402ab0093cb6a837',
            '919df28d15e02fd2c875e314d2625a92e6404bbe9248793dae511a4279e80f74',
            'b40cd8bcc1126dade1ba2cc51f2beb0385c0d0368d85b0e7ec66c0186c716060',
            'c57f0d3f77404b73a64e9535c672463e9a0d5b7a682e65dc280663417a0ed9d3',
            'caae86fc495c63da747449d124eeac4e7623f7b35803dcf9ed8dcf92c8c7e691',
            'd6d2080a4690d4d55e4e8ad1a1d4284455ea517fbfc4a1fc536a4446568d4e42',
            'edf32fdb8c5e95501b6b51e8505501f8664948502ea1c7bcec17517ce7a7fdce',
        ],
        'process-code-review/SKILL.md' => [
            '0f17a6de2e3d14b8076fb18d655f61c77435e227d22a35f2abda7e2f11e9e191',
            '165e34569050dcca76ec50f289153e37e197bbee9731f4594b1f58841dc5b3e9',
            '1ffba8db64543829d7ad0b957f0ae020b5e04a7eacb9f6d125dd054d90654a31',
            '20568ef2378243b0d32cb76b8e9afd7d3bcb47a86f4c5b25b2ca09901cec1a27',
            '307c5de14fcea5ab338df516308af686f5c09df51b3fbef6c9de781c435c0bd8',
            '445f5c4a1976ce8f7261d582029aed2c1ab93d2e02c5faa2844aff409ab3d07f',
            '5db409347c40675299d7b030a6f70953221128843d3cef424082001312260a46',
            '6ae4478cd703b9fa595707a4d30505b87defdcf7ec0a75ff0ca6457b86fe8728',
            '70f3f09576deaf5937bff336d61c893d2da59992f081e85e2a3d70982862f860',
            '7ce8a2afae156ee2d9561772e552ead1a541978fda7b2a7ee76247a72fb09317',
            '8f0d47e5622570ff31cdcf3d5c7cb8cec00492f919b7e45e644e1ac879e2bd06',
            '8f8b7c523e03ff81dd69fff3fc445b0b86aca80395adcfe005723ff02c0b81cd',
            'aed3c06039c0362007fb758b141180d4c50285d584195aacd49a15e317c167c0',
            'b14b6bd821be7b8e2ed299fe6a161b1c1517237c037a9f343779b4f94910df6d',
            'c3357c6244dd3ac1350492f85f0d40f53d1d262650ecdcac8f1d3f530bc02131',
            'cad5fb7c46c130bbdfca8d5d50b9c09845ef199a64abbd9498694067d593ae83',
            'da1360f45ad31737f6db1a583fd08ce6142304f611cff9069cd56b01c14302fd',
            'e8fc86456149179ff7d0d343a54c9e77701ae0cf390acb9e4087704b01bf5894',
        ],
        'security-audit/SKILL.md' => [
            '1e6e67186e1ef39a6afe3a58df802fd7002d6f51ea3e1fbb6fab2569c52b0430',
            '6d71ca3fd6b7325cae49d3f8030be8dc23e7042f8477e0f647f4efd785d7ee65',
            '759906d6351ee8f5287bc8575af0a00a712448550d05ca5cc507632875c9fba9',
            '953b7b75b752d38718b26ab97fba63e9da95ab49ec5e0ff114630097a77b6b53',
            '991cf006f6706dfa5f209bf83f2e310a2106b917e390d9c570e385d458ccb516',
            'eda3713658187cfd83a85a91fcafda88664406bb10c543f2a1ebd3b6a67a3061',
        ],
        'simplification-audit/SKILL.md' => [
            '06c78fe72c16659ec95d6ffc50a1fd97ea84e940c72b1c267a226c4935fb1d40',
            '130093520c91eef1e96925d7a327ad50b12432b9bdee2df439a69d3ec4cf631c',
            '20ec8cf2253203133c71fb12765ae7272a86eb2347489866b59d129abea99291',
            '2562a913311205e0451b0b00e9ec915f9fae90d36c16548d8d1d5d38829d8546',
            '277f8d6ba59dc12bdb97280497976d48f86bac36d4f8caa36ab8974bb9268ff6',
            '5e0f980b57244653b4b1f28ad8fe95ebf6ff6b6635b4b38344a93cd4815d931b',
            '8807756db68d0e101a21c8fc4a52c13fd709b0fb905e452b9dc4ac41739147d8',
            '97f071a12bea49775631e969e2b9f59215a35d7bc69540d79b3be1ff68fcad97',
            'de962e9d1a7c6078f11f26bfd3e14833d567b576f7e5c7a22263b963c53ef00a',
            'ea5e1113d31586acbf5b824bb693b9ccc0d7a876cc59da5358d168ec1cb537a7',
        ],
        'test-assignment/SKILL.md' => [
            '44c6d7a939f397cc7ca2b089880d6e92219420a5970ebaa1d87de021b006e969',
            '6289da465936f869388c76b8c57dc0229c013748a9b26d1fe830c983935234c0',
            '7e43497f0b29021ffdd7218b5d75ac8a2891ad6bbbca46402363019efd02c8a2',
            '94400db0e99a1a262764a95b7a6f28901762c2d64e776f6dcc2e651f9c364b41',
            'ab8fb881b9f88fa9bb3286b71d619d9a534e2f694d89f6c66a8cc2fc63d7512f',
            'db4f2eda3c47f30c028a5e2d57ad535f8f5727e44f85e9f103e06bd18475e9af',
            'e0b0a6928888a2a51c4a6a9d1c48d8dd81ace86fab17b2b045d427c6fd63ab1f',
            'fe914308601abc3722f63da6f3b4b00e6bad3d37a565028d984ba45070f58d64',
        ],
        'verify-merge-readiness/SKILL.md' => [
            '0ecc33f26fe8298d826f4a4d083a910530cf36b903fce59acd83bc01e0472812',
            '141e14cdf796d9b48ea66551b3f1e78240611e885ff594ae53712568fedcd615',
            '211a115d0cb9fb4bf0a5f37170108c41527a14dc56452c46848fe07574417a70',
            '30c2a0a140630e1fc73730c078952254287fca4bcc91119cad9fb6cd104cffaf',
            '59cb0434338adcaae0fb9ad476dde996f4646a7114a7dcf886e74a1f355614d6',
            '605041ee3db31b3cdae1ef2dd1efba02b7eee55e679e0ba16472295dede57808',
            '68ebad256457da34f8815b3ed0497e2ce97015aacf6902bf00b645ee2513cb53',
            '70eba544a84628cb743951509217dff3cea34651c66ebec452e34a7b3fc726e9',
            '75deb2c16e60d6230daec1ab5fe41b63758515780ad940c12ebcf1798d3d8bd0',
            '78bf76e9508a183d5a1de356e4fa77e43c902704759b7eb76b137d65573f9ab3',
            '7bb5083aeb4be9a78d0f761c29a6955b522890d3b174aecfe61b3fdfe7b08b3b',
            '80441920d6c67fb415701884f22a3fb72835441d4da34ecc5e8c25944d5ff243',
            '981b39c742b97cab7a0f52e796ce1a0cfd49c0328daf456f108ec92c33beceda',
            'a0948939ce89a5d11a2d286b6164e1d2a3ce4fdd29cc7583397ddc99498b3495',
            'a1c0ee8c86166cdb959d8b99ad568d63d0b2ec9b5ee1d876046bf138d6f9b587',
            'b14683e39d3adbd4635d801e35007413e0a7ba39347809a6415b62eac44ebb14',
            'b753a4c40f3ae94ea35e7bd429a36537bfd550f0833b071774ff264110cd0551',
            'bd5d950b3df606a587abe596a9a376010b87bde2991de91bef5019ef8aff5fca',
            'e54aee3db33dd5082f6637da727744b3a63fb8b5ca430364e7ca96ed42206321',
            'f512279d43d9fece3771164ac09119c34992f57f2fb96b53661fe27921820135',
        ],
    ];

    public static function isOriginalCopy(string $relative, string $target): bool
    {
        $digests = self::DIGESTS[$relative] ?? [];

        if ($digests === []
            || !is_file($target)
            || is_link($target)
            || is_link(dirname($target))
            || is_link(dirname($target, 2))
            || is_link(dirname($target, 3))
        ) {
            return false;
        }

        return in_array(hash_file('sha256', $target), $digests, strict: true);
    }

}
