<?php
$basePath = $basePath ?? (function () {
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    return $scriptDir === '/' ? '' : rtrim($scriptDir, '/');
})();
?>
<article class="legal-page" aria-labelledby="giulia-title">
    <section class="creator-hero">
        <img src="<?php echo e($basePath); ?>/assets/images/Giulia.png" alt="<?php echo e(t('giulia_page.heading')); ?>" class="creator-photo" loading="lazy" decoding="async">
        <div>
            <p class="commercial-eyebrow"><?php echo e(t('giulia_page.eyebrow')); ?></p>
            <h1 id="giulia-title"><?php echo e(t('giulia_page.heading')); ?></h1>
            <p><?php echo e(t('giulia_page.tagline')); ?></p>
        </div>
    </section>

    <section class="commercial-card">
        <h2><?php echo e(t('giulia_page.intro_title')); ?></h2>
        <p><?php echo e(t('giulia_page.intro_body')); ?></p>
    </section>

    <section class="commercial-card">
        <h2><?php echo e(t('giulia_page.who_title')); ?></h2>
        <p><?php echo e(t('giulia_page.who_body')); ?></p>
    </section>

    <section class="commercial-card">
        <h2><?php echo e(t('giulia_page.capabilities_title')); ?></h2>
        <ul style="list-style: disc; padding-left: 1.3rem; display: grid; gap: 0.5rem;">
            <li><?php echo e(t('giulia_page.capability_1')); ?></li>
            <li><?php echo e(t('giulia_page.capability_2')); ?></li>
            <li><?php echo e(t('giulia_page.capability_3')); ?></li>
            <li><?php echo e(t('giulia_page.capability_4')); ?></li>
            <li><?php echo e(t('giulia_page.capability_5')); ?></li>
        </ul>
    </section>

    <section class="commercial-card">
        <h2><?php echo e(t('giulia_page.how_title')); ?></h2>
        <p><?php echo e(t('giulia_page.how_body')); ?></p>
    </section>

    <section class="commercial-card">
        <h2><?php echo e(t('giulia_page.privacy_title')); ?></h2>
        <p><?php echo e(t('giulia_page.privacy_body')); ?></p>
    </section>

    <section class="commercial-card creator-contact-card">
        <h2><?php echo e(t('giulia_page.cta_title')); ?></h2>
        <p><?php echo e(t('giulia_page.cta_body')); ?></p>
        <p class="home-commercial-link-wrap">
            <a class="admin-action-link" href="<?php echo e(appUrl('login')); ?>"><?php echo e(t('giulia_page.cta_login')); ?></a>
            <a class="admin-action-link admin-action-link-secondary" href="<?php echo e(appUrl('register')); ?>"><?php echo e(t('giulia_page.cta_register')); ?></a>
        </p>
    </section>
</article>
