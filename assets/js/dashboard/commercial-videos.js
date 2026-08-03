(() => {
  const config = window.DashboardConfig || {};
  const apiUrl = config.apiCommercial;
  const feedback = window.DashboardFeedback;

  if (!apiUrl || !window.AppAPI) return;

  const panel = document.querySelector('[data-settings-panel="commercial-videos"]');
  if (!panel) return;

  function notifyError(message) {
    if (feedback?.error) {
      feedback.error('Oops!', message);
      return;
    }
    console.error(message);
  }

  function collectVideos() {
    return Array.from(panel.querySelectorAll('[data-commercial-video-row]')).map((row) => {
      const title = row.querySelector('[data-field="title"]')?.value?.trim() || '';
      const url = row.querySelector('[data-field="url"]')?.value?.trim() || '';
      return { title, url };
    }).filter((video) => video.title !== '' || video.url !== '');
  }

  async function saveVideos() {
    const videos = collectVideos();
    if (!videos.length) {
      notifyError('Insert at least one YouTube link.');
      return;
    }

    try {
      const res = await AppAPI.commercial.update(apiUrl, videos);
      if (!res?.ok) {
        notifyError('Save failed: ' + (res?.error || 'unknown'));
        return;
      }

      if (feedback?.reloadSettingsTabWithSuccess) {
        feedback.reloadSettingsTabWithSuccess('commercial-videos', 'Done', 'Commercial videos updated successfully.');
      } else {
        location.reload();
      }
    } catch (error) {
      notifyError('Error saving videos.');
      console.error(error);
    }
  }

  panel.addEventListener('click', (event) => {
    const saveButton = event.target.closest('.settings-commercial-videos-save');
    if (saveButton) {
      saveVideos();
    }
  });
})();