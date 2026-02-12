(function () {
    function setupHls(video) {
        var hlsSrc = video.getAttribute('data-hls-src');
        if (!hlsSrc) {
            return;
        }

        if (video.canPlayType('application/vnd.apple.mpegurl')) {
            video.src = hlsSrc;
            return;
        }

        if (window.Hls && window.Hls.isSupported()) {
            var hls = new window.Hls();
            hls.loadSource(hlsSrc);
            hls.attachMedia(video);
            video._sevenlsHls = hls;
            return;
        }

        video.src = hlsSrc;
    }

    function clampTime(video, time) {
        if (!isFinite(video.duration)) {
            return Math.max(0, time);
        }

        return Math.min(Math.max(0, time), video.duration);
    }

    function updateToggleLabel(button, video) {
        if (!button) {
            return;
        }

        if (video.paused) {
            button.textContent = 'Play';
            button.classList.remove('is-playing');
        } else {
            button.textContent = 'Pause';
            button.classList.add('is-playing');
        }
    }

    function showError(container, message) {
        var errorEl = container.querySelector('[data-video-error]');
        if (!errorEl) {
            return;
        }
        errorEl.textContent = message;
        errorEl.classList.add('is-visible');
    }

    function clearError(container) {
        var errorEl = container.querySelector('[data-video-error]');
        if (!errorEl) {
            return;
        }
        errorEl.textContent = '';
        errorEl.classList.remove('is-visible');
    }

    function describeError(video) {
        if (!video.error) {
            return 'Video failed to load.';
        }

        switch (video.error.code) {
            case 1:
                return 'Video playback was aborted.';
            case 2:
                return 'Network error while downloading the video.';
            case 3:
                return 'Video is corrupted or the format is not supported.';
            case 4:
                return 'Video format is not supported or the source is invalid.';
            default:
                return 'Video failed to load.';
        }
    }

    function setupControls(container, video) {
        var toggle = container.querySelector('.sevenls-video-toggle');
        var skipButtons = container.querySelectorAll('.sevenls-video-skip');

        if (toggle) {
            toggle.addEventListener('click', function () {
                if (video.paused) {
                    video.play();
                } else {
                    video.pause();
                }
            });
        }

        skipButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                var skip = parseFloat(button.getAttribute('data-skip') || '0');
                if (isNaN(skip)) {
                    return;
                }
                video.currentTime = clampTime(video, video.currentTime + skip);
            });
        });

        video.addEventListener('play', function () {
            updateToggleLabel(toggle, video);
        });
        video.addEventListener('pause', function () {
            updateToggleLabel(toggle, video);
        });

        video.addEventListener('error', function () {
            var message = describeError(video);
            message += ' Check Content-Type video/mp4 and Accept-Ranges bytes.';
            showError(container, message);
        });

        video.addEventListener('loadeddata', function () {
            clearError(container);
        });

        updateToggleLabel(toggle, video);
    }

    function initPlayers() {
        var containers = document.querySelectorAll('.sevenls-video-player');
        containers.forEach(function (container) {
            var video = container.querySelector('video');
            if (!video) {
                return;
            }
            setupHls(video);
            setupControls(container, video);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPlayers);
    } else {
        initPlayers();
    }
})();
