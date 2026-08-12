<?php
/**
 * AI assistant widget — floating trigger + panel, restricted to admin and
 * department_manager. Two modes depending on assistantIsAvailable():
 * - available: chat with the AI (assistant.js talks to api-assistant).
 * - not available (no provider configured yet): a static tutorial menu
 *   covering the main features, so admins/managers still have guidance
 *   while no AI is wired up. Once a provider is configured, the same
 *   trigger switches to the chat automatically.
 */
$assistantRole = currentUser()['role'] ?? '';
if (!isLoggedIn() || !in_array($assistantRole, ['admin', 'department_manager'], true)) {
    return;
}

$assistantAvailable = assistantIsAvailable();

$assistantTutorialTopics = [
    ['id' => 'shifts', 'title' => t('assistant.tutorial.shifts.title'), 'steps' => [
        t('assistant.tutorial.shifts.step1'), t('assistant.tutorial.shifts.step2'),
        t('assistant.tutorial.shifts.step3'), t('assistant.tutorial.shifts.step4'),
        t('assistant.tutorial.shifts.step5'),
    ]],
    ['id' => 'planning', 'title' => t('assistant.tutorial.planning.title'), 'steps' => [
        t('assistant.tutorial.planning.step1'), t('assistant.tutorial.planning.step2'),
        t('assistant.tutorial.planning.step3'), t('assistant.tutorial.planning.step4'),
        t('assistant.tutorial.planning.step5'), t('assistant.tutorial.planning.step6'),
    ]],
    ['id' => 'employees', 'title' => t('assistant.tutorial.employees.title'), 'steps' => [
        t('assistant.tutorial.employees.step1'), t('assistant.tutorial.employees.step2'),
        t('assistant.tutorial.employees.step3'), t('assistant.tutorial.employees.step4'),
    ]],
    ['id' => 'notifications', 'title' => t('assistant.tutorial.notifications.title'), 'steps' => [
        t('assistant.tutorial.notifications.step1'), t('assistant.tutorial.notifications.step2'),
        t('assistant.tutorial.notifications.step3'), t('assistant.tutorial.notifications.step4'),
    ]],
    ['id' => 'documents', 'title' => t('assistant.tutorial.documents.title'), 'steps' => [
        t('assistant.tutorial.documents.step1'), t('assistant.tutorial.documents.step2'),
        t('assistant.tutorial.documents.step3'), t('assistant.tutorial.documents.step4'),
    ]],
    ['id' => 'attendance', 'title' => t('assistant.tutorial.attendance.title'), 'steps' => [
        t('assistant.tutorial.attendance.step1'), t('assistant.tutorial.attendance.step2'),
        t('assistant.tutorial.attendance.step3'), t('assistant.tutorial.attendance.step4'),
    ]],
    ['id' => 'calendar', 'title' => t('assistant.tutorial.calendar.title'), 'steps' => [
        t('assistant.tutorial.calendar.step1'), t('assistant.tutorial.calendar.step2'),
        t('assistant.tutorial.calendar.step3'), t('assistant.tutorial.calendar.step4'),
    ]],
];
?>
<button type="button" class="assistant-trigger" data-assistant-open aria-haspopup="dialog" aria-controls="assistant-panel" title="<?php echo e(t('assistant.open')); ?>">
    <img src="<?php echo $basePath; ?>/assets/images/Giulia.png" alt="" class="assistant-trigger-icon">
    <span class="assistant-trigger-label"><?php echo e(t('assistant.title')); ?></span>
</button>
<section class="assistant-panel" id="assistant-panel" hidden role="dialog" aria-modal="false" aria-labelledby="assistant-panel-title">
    <div class="assistant-panel-head">
        <img src="<?php echo $basePath; ?>/assets/images/Giulia.png" alt="" class="assistant-panel-head-icon">
        <h2 id="assistant-panel-title"><?php echo e(t('assistant.title')); ?></h2>
        <div class="assistant-panel-head-actions">
            <?php if ($assistantAvailable): ?>
                <button type="button" class="assistant-clear" data-assistant-clear title="<?php echo e(t('assistant.clear')); ?>"><?php echo e(t('assistant.clear')); ?></button>
            <?php endif; ?>
            <button type="button" class="assistant-close" data-assistant-close aria-label="<?php echo e(t('assistant.close')); ?>">&times;</button>
        </div>
    </div>

    <?php if ($assistantAvailable): ?>
        <label class="assistant-auto-mode" title="<?php echo e(t('assistant.auto_mode_hint')); ?>">
            <input type="checkbox" data-assistant-auto-mode>
            <span><?php echo e(t('assistant.auto_mode_label')); ?></span>
        </label>
        <div class="assistant-messages" data-assistant-messages>
            <p class="assistant-empty" data-assistant-empty><?php echo e(t('assistant.empty')); ?></p>
        </div>
        <div class="assistant-quick-questions" data-assistant-quick-questions>
            <button type="button" class="assistant-quick-question-chip" data-assistant-quick-question><?php echo e(t('assistant.quick_question_1')); ?></button>
            <button type="button" class="assistant-quick-question-chip" data-assistant-quick-question><?php echo e(t('assistant.quick_question_2')); ?></button>
            <button type="button" class="assistant-quick-question-chip" data-assistant-quick-question><?php echo e(t('assistant.quick_question_3')); ?></button>
            <button type="button" class="assistant-quick-question-chip" data-assistant-quick-question><?php echo e(t('assistant.quick_question_4')); ?></button>
            <button type="button" class="assistant-quick-question-chip" data-assistant-quick-question><?php echo e(t('assistant.quick_question_5')); ?></button>
            <button type="button" class="assistant-quick-question-chip" data-assistant-quick-question><?php echo e(t('assistant.quick_question_6')); ?></button>
            <button type="button" class="assistant-quick-question-chip" data-assistant-quick-question><?php echo e(t('assistant.quick_question_7')); ?></button>
        </div>
        <form class="assistant-composer" data-assistant-form>
            <textarea class="assistant-input" data-assistant-input rows="1" placeholder="<?php echo e(t('assistant.placeholder')); ?>" required></textarea>
            <button type="submit" class="assistant-send" data-assistant-send><?php echo e(t('assistant.send')); ?></button>
        </form>
    <?php else: ?>
        <div class="assistant-tutorial" data-assistant-tutorial>
            <p class="assistant-tutorial-notice"><?php echo e(t('assistant.tutorial_notice')); ?></p>
            <ul class="assistant-tutorial-menu" data-assistant-tutorial-menu>
                <?php foreach ($assistantTutorialTopics as $topic): ?>
                    <li>
                        <button type="button" class="assistant-tutorial-menu-item" data-assistant-tutorial-topic="<?php echo e($topic['id']); ?>">
                            <?php echo e($topic['title']); ?>
                        </button>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php foreach ($assistantTutorialTopics as $topic): ?>
                <div class="assistant-tutorial-steps" data-assistant-tutorial-steps="<?php echo e($topic['id']); ?>" hidden>
                    <button type="button" class="assistant-tutorial-back" data-assistant-tutorial-back><?php echo e(t('assistant.tutorial_back')); ?></button>
                    <h3><?php echo e($topic['title']); ?></h3>
                    <ol>
                        <?php foreach ($topic['steps'] as $step): ?>
                            <li><?php echo e($step); ?></li>
                        <?php endforeach; ?>
                    </ol>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
