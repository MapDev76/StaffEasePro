<?php $currentUser = currentUser(); ?>
<?php
$basePath = $basePath ?? (function () {
	$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
	return $scriptDir === '/' ? '' : rtrim($scriptDir, '/');
})();
$localeCode = strtolower(substr((string) appLocale(), 0, 2));
$commercialSeoCopyByLocale = [
	'it' => [
		'title' => 'Perche scegliere StaffEase Pro per la gestione turni',
		'body' => 'StaffEase Pro supporta la gestione turni hotel, il controllo presenze, la firma digitale e la condivisione documenti aziendali. E una soluzione cloud per coordinare reparti e personale con meno errori e piu velocita operativa.',
	],
	'fr' => [
		'title' => 'Pourquoi choisir StaffEase Pro pour vos plannings',
		'body' => 'StaffEase Pro facilite le planning des equipes, le suivi des presences, la signature numerique et la gestion documentaire RH. C est une solution cloud pensee pour coordonner les services avec plus de fiabilite et de rapidite.',
	],
	'en' => [
		'title' => 'Why choose StaffEase Pro for shift planning',
		'body' => 'StaffEase Pro improves hotel staff scheduling, attendance control, digital signatures and HR document management. It is a cloud platform built to coordinate departments with greater accuracy and faster daily operations.',
	],
];
$commercialSeoCopy = $commercialSeoCopyByLocale[$localeCode] ?? $commercialSeoCopyByLocale['en'];
$commercialVideos = getCommercialVideos();
?>
<article class="home-page commercial-page" aria-labelledby="commercial-title">
        <section class="commercial-hero">
            <p class="commercial-eyebrow"><?php echo e(t('common.app_name')); ?></p>
            <h1 id="commercial-title"><?php echo e(t('commercial.title', ['fallback' => 'StaffEase Pro en action'])); ?></h1>
            <p class="commercial-lead">
                <?php echo e(t('commercial.lead', ['fallback' => 'Scopri una piattaforma progettata per semplificare pianificazione turni, presenze e gestione operativa.'])); ?>
            </p>
        </section>

        <section class="commercial-project-note">
            <h2><?php echo e(t('commercial.projects_title', ['fallback' => 'Progetti in corso'])); ?></h2>
            <p><?php echo e(t('commercial.projects_body', ['fallback' => 'HotelEase Pro e GuestEase Pro estenderanno la base StaffEase Pro con strumenti verticali per strutture ricettive e team operativi.'])); ?></p>
            <p class="commercial-emphasis"><?php echo e(t('commercial.projects_sell', ['fallback' => 'Una suite semplice, connessa e scalabile per qualsiasi azienda del turismo e dell ospitalita.'])); ?></p>
        </section>

        <section class="commercial-feature-grid">
            <article class="commercial-card commercial-feature-card">
                <span class="commercial-step-number">1</span>
                <h2><?php echo e(t('commercial.feature_1_title', ['fallback' => 'Automatizzazione dei turni di lavoro'])); ?></h2>
                <p class="commercial-video-title"><?php echo e(t('commercial.feature_1_video_title', ['fallback' => 'StaffEase Pro: Come automatizzare i turni di lavoro in 3 passaggi'])); ?></p>
                <p><?php echo e(t('commercial.feature_1_intro')); ?></p>
                <h3><?php echo e(t('commercial.how_it_works_title', ['fallback' => 'Come funziona'])); ?></h3>
                <ul>
                    <li><?php echo e(t('commercial.feature_1_step_1')); ?></li>
                    <li><?php echo e(t('commercial.feature_1_step_2')); ?></li>
                    <li><?php echo e(t('commercial.feature_1_step_3')); ?></li>
                    <li><?php echo e(t('commercial.feature_1_step_4')); ?></li>
                </ul>
                <h3><?php echo e(t('commercial.benefits_title', ['fallback' => 'Vantaggi'])); ?></h3>
                <ul>
                    <li><?php echo e(t('commercial.feature_1_benefit_1')); ?></li>
                    <li><?php echo e(t('commercial.feature_1_benefit_2')); ?></li>
                    <li><?php echo e(t('commercial.feature_1_benefit_3')); ?></li>
                </ul>
            </article>
            <article class="commercial-card commercial-feature-card">
                <span class="commercial-step-number">2</span>
                <h2><?php echo e(t('commercial.feature_2_title', ['fallback' => 'Sicurezza delle firme digitali (Wi-Fi/IP aziendale)'])); ?></h2>
                <p class="commercial-video-title"><?php echo e(t('commercial.feature_2_video_title', ['fallback' => 'Firme digitali sicure con StaffEase Pro: Autenticazione e tracciabilita'])); ?></p>
                <p><?php echo e(t('commercial.feature_2_intro')); ?></p>
                <h3><?php echo e(t('commercial.how_it_works_title', ['fallback' => 'Come funziona'])); ?></h3>
                <ul>
                    <li><?php echo e(t('commercial.feature_2_step_1')); ?></li>
                    <li><?php echo e(t('commercial.feature_2_step_2')); ?></li>
                    <li><?php echo e(t('commercial.feature_2_step_3')); ?></li>
                </ul>
                <h3><?php echo e(t('commercial.benefits_title', ['fallback' => 'Vantaggi'])); ?></h3>
                <ul>
                    <li><?php echo e(t('commercial.feature_2_benefit_1')); ?></li>
                    <li><?php echo e(t('commercial.feature_2_benefit_2')); ?></li>
                    <li><?php echo e(t('commercial.feature_2_benefit_3')); ?></li>
                </ul>
            </article>
            <article class="commercial-card commercial-feature-card">
                <span class="commercial-step-number">3</span>
                <h2><?php echo e(t('commercial.feature_3_title', ['fallback' => 'Centralizzazione della gestione dei dipendenti'])); ?></h2>
                <p class="commercial-video-title"><?php echo e(t('commercial.feature_3_video_title', ['fallback' => 'StaffEase Pro: Tutto il team in un unica piattaforma'])); ?></p>
                <p><?php echo e(t('commercial.feature_3_intro')); ?></p>
                <h3><?php echo e(t('commercial.how_it_works_title', ['fallback' => 'Come funziona'])); ?></h3>
                <ul>
                    <li><?php echo e(t('commercial.feature_3_step_1')); ?></li>
                    <li><?php echo e(t('commercial.feature_3_step_2')); ?></li>
                    <li><?php echo e(t('commercial.feature_3_step_3')); ?></li>
                    <li><?php echo e(t('commercial.feature_3_step_4')); ?></li>
                </ul>
                <h3><?php echo e(t('commercial.benefits_title', ['fallback' => 'Vantaggi'])); ?></h3>
                <ul>
                    <li><?php echo e(t('commercial.feature_3_benefit_1')); ?></li>
                    <li><?php echo e(t('commercial.feature_3_benefit_2')); ?></li>
                    <li><?php echo e(t('commercial.feature_3_benefit_3')); ?></li>
                </ul>
            </article>
        </section>

        <section class="commercial-videos" aria-label="<?php echo e(t('commercial.demo_videos_title', ['fallback' => 'Demo videos'])); ?>">
            <?php foreach ($commercialVideos as $index => $video): ?>
                <?php $thumbUrl = 'https://img.youtube.com/vi/' . rawurlencode((string) $video['id']) . '/hqdefault.jpg'; ?>
                <article class="commercial-video-card commercial-video-card-compact">
                    <a class="commercial-video-thumb-link" href="<?php echo e($video['url']); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo e(($video['title'] ?? 'Video') . ' - YouTube'); ?>">
                        <img class="commercial-video-thumb" src="<?php echo e($thumbUrl); ?>" alt="<?php echo e($video['title'] ?? 'Video'); ?>" loading="lazy" decoding="async">
                        <span class="commercial-video-play" aria-hidden="true">▶</span>
                    </a>
                    <h3><?php echo e($video['title'] ?? ('Video ' . (string) ($index + 1))); ?></h3>
                </article>
            <?php endforeach; ?>
        </section>

        <section class="commercial-project-note" aria-label="<?php echo e($commercialSeoCopy['title']); ?>">
            <h2><?php echo e($commercialSeoCopy['title']); ?></h2>
            <p><?php echo e($commercialSeoCopy['body']); ?></p>
        </section>
</article>
