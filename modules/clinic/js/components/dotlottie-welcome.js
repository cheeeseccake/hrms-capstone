// ==========================================================================
// HRMS Capstone — DotLottie welcome animation
// The <dotlottie-wc> player is loaded from a CDN as a module. When the
// player itself cannot load (offline, blocked CDN, unsupported browser) the
// custom element stays undefined, and when the animation or its WASM cannot
// load the element dispatches a loadError event. Either way the welcome
// screen should degrade to a plain empty state instead of a blank gap.
// ==========================================================================

function initDotLottieWelcome() {
  'use strict';

  /* Player script never defined the element: fall back immediately. */
  var playerScript = document.querySelector('script[src*="dotlottie-wc"]');
  window.setTimeout(function () {
    if (window.customElements === undefined || !window.customElements.get('dotlottie-wc')) {
      markWelcomeAnimationsFailed();
    }
  }, playerScript ? 6000 : 0);

  /* Player defined, but the animation could not be loaded. */
  ['loadError', 'error'].forEach(function (eventName) {
    document.addEventListener(eventName, function (event) {
      var player = event.target;
      if (!player || typeof player.closest !== 'function' || !player.closest('dotlottie-wc')) return;
      markWelcomeAnimationsFailed();
    });
  });

  function markWelcomeAnimationsFailed() {
    document.querySelectorAll('.eh-visit-welcome').forEach(function (welcome) {
      welcome.classList.add('eh-visit-welcome--no-animation');
    });
  }
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initDotLottieWelcome);
} else {
  initDotLottieWelcome();
}
