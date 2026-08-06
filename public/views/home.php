<?php $currentUser = currentUser(); ?>
<?php
$basePath = $basePath ?? (function () {
	$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
	return $scriptDir === '/' ? '' : rtrim($scriptDir, '/');
})();
$localeCode = strtolower(substr((string) appLocale(), 0, 2));
$homeSeoCopyByLocale = [
	'it' => [
		'title' => 'Software gestione personale per hotel e team operativi',
		'body' => 'StaffEase Pro e un software di gestione turni e presenze pensato per hotel, ristorazione e aziende di servizi. La piattaforma unisce pianificazione staff, firma digitale dipendenti, gestione documenti HR e comunicazioni interne in un unico ambiente web.',
	],
	'fr' => [
		'title' => 'Logiciel de gestion du personnel pour hotels et equipes',
		'body' => 'StaffEase Pro est un logiciel de planning du personnel et de pointage pour hotels, restauration et entreprises de services. La plateforme centralise plannings, signatures numeriques, documents RH et communication interne dans un seul espace web.',
	],
	'en' => [
		'title' => 'Workforce management software for hotels and operations teams',
		'body' => 'StaffEase Pro is a workforce management app for hotels, hospitality and service businesses. It combines shift scheduling, attendance tracking, digital employee signatures, HR document workflows and internal communication in one platform.',
	],
];
$homeSeoCopy = $homeSeoCopyByLocale[$localeCode] ?? $homeSeoCopyByLocale['en'];
?>
<section class="home-page" aria-labelledby="home-title">
	<h1 id="home-title"><?php echo e(t('home.welcome_title')); ?></h1>
	<p><?php echo e(t('home.welcome_subtitle')); ?></p>
	
	<section class="home-intro">
		<h2 id="home-intro-title"><?php echo e(t('home.intro_title')); ?></h2>

		<p>
			<?php echo e(t('home.lead_1')); ?>
			<?php echo e(t('home.lead_2')); ?>
		</p>
		<p>
			<?php echo e(t('home.lead_3')); ?>
		</p>

		<h3><?php echo e(t('home.audience_title')); ?></h3>
		<ul style="list-style: none; padding: 0;" aria-labelledby="home-intro-title">
			<li style="display: flex; align-items: center; margin-bottom: 0.5rem; padding: 0.5rem; background-color: var(--color-badge-bg); border-radius: var(--border-radius-sm); color: var(--color-badge-text);">
				<img src="<?php echo e($basePath); ?>/assets/icons/alert-circle.svg" alt="" aria-hidden="true" style="width: 16px; height: 16px; margin-right: 0.5rem;" />
				<?php echo e(t('home.audience_1')); ?>
			</li>
			<li style="display: flex; align-items: center; margin-bottom: 0.5rem; padding: 0.5rem; background-color: var(--color-badge-bg); border-radius: var(--border-radius-sm); color: var(--color-badge-text);">
				<img src="<?php echo e($basePath); ?>/assets/icons/alert-circle.svg" alt="" aria-hidden="true" style="width: 16px; height: 16px; margin-right: 0.5rem;" />
				<?php echo e(t('home.audience_2')); ?>
			</li>
			<li style="display: flex; align-items: center; margin-bottom: 0.5rem; padding: 0.5rem; background-color: var(--color-badge-bg); border-radius: var(--border-radius-sm); color: var(--color-badge-text);">
				<img src="<?php echo e($basePath); ?>/assets/icons/alert-circle.svg" alt="" aria-hidden="true" style="width: 16px; height: 16px; margin-right: 0.5rem;" />
				<?php echo e(t('home.audience_3')); ?>
			</li>
			<li style="display: flex; align-items: center; margin-bottom: 0.5rem; padding: 0.5rem; background-color: var(--color-badge-bg); border-radius: var(--border-radius-sm); color: var(--color-badge-text);">
				<img src="<?php echo e($basePath); ?>/assets/icons/alert-circle.svg" alt="" aria-hidden="true" style="width: 16px; height: 16px; margin-right: 0.5rem;" />
				<?php echo e(t('home.audience_4')); ?>
			</li>
			<li style="display: flex; align-items: center; margin-bottom: 0.5rem; padding: 0.5rem; background-color: var(--color-badge-bg); border-radius: var(--border-radius-sm); color: var(--color-badge-text);">
				<img src="<?php echo e($basePath); ?>/assets/icons/alert-circle.svg" alt="" aria-hidden="true" style="width: 16px; height: 16px; margin-right: 0.5rem;" />
				<?php echo e(t('home.audience_5')); ?>
			</li>
		</ul>

		<!-- Feature panels -->
		<section class="simple-div-container" aria-label="<?php echo e(t('home.intro_title')); ?>">
			<article class="simple-div">
				<p class="simple-div-title"><?php echo e(t('home.feature_shifts_title')); ?></p>
				<p class="simple-div-content"><?php echo e(t('home.feature_shifts_body')); ?></p>
			</article>
			<article class="simple-div">
				<p class="simple-div-title"><?php echo e(t('home.feature_attendance_title')); ?></p>
				<p class="simple-div-content"><?php echo e(t('home.feature_attendance_body')); ?></p>
			</article>
			<article class="simple-div">
				<p class="simple-div-title"><?php echo e(t('home.feature_documents_title')); ?></p>
				<p class="simple-div-content"><?php echo e(t('home.feature_documents_body')); ?></p>
			</article>
			<article class="simple-div">
				<p class="simple-div-title"><?php echo e(t('home.feature_reporting_title')); ?></p>
				<p class="simple-div-content"><?php echo e(t('home.feature_reporting_body')); ?></p>
			</article>
			<article class="simple-div">
				<p class="simple-div-title"><?php echo e(t('home.feature_requests_title')); ?></p>
				<p class="simple-div-content"><?php echo e(t('home.feature_requests_body')); ?></p>
			</article>
		</section>

		<section class="simple-div" aria-label="<?php echo e($homeSeoCopy['title']); ?>">
			<p class="simple-div-title"><?php echo e($homeSeoCopy['title']); ?></p>
			<p class="simple-div-content"><?php echo e($homeSeoCopy['body']); ?></p>
		</section>

		<aside class="simple-div home-video-teaser">
			<p class="home-commercial-link-wrap">
				<a class="admin-action-link" href="<?php echo e(appUrl('register')); ?>"><?php echo e(t('signup.home_cta')); ?></a>
				<a class="admin-action-link admin-action-link-secondary" href="<?php echo e(appUrl('commercial')); ?>"><?php echo e(t('common.commercial_cta')); ?></a>
			</p>
		</aside>

	</section>
</section>