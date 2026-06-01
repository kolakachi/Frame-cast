/**
 * PostHog product analytics for the marketing site. Loads the official
 * snippet, then init()s with the public project key. The key is safe to
 * expose (it's write-only — capture-events-only).
 *
 * To activate: open this file once, paste your project key from
 * https://app.posthog.com/project/settings into POSTHOG_KEY below,
 * and redeploy. Until that's done, the loader bails silently.
 *
 * Captured events on the marketing site:
 *  - $pageview (auto)
 *  - 'cta_click' — on hero / pricing CTAs (manually wired where useful)
 *
 * For app-side analytics (auth flow, project creation, generation events,
 * upgrade clicks, etc.), see web/src/main.js — that init uses VITE_POSTHOG_KEY
 * so the key isn't baked into the JS bundle but supplied at build time.
 */
(function () {
  // ⤵ PASTE PROJECT KEY HERE (starts with "phc_" in PostHog Cloud) ⤵
  var POSTHOG_KEY = '';

  if (!POSTHOG_KEY) return; // not yet configured — no-op

  // Standard PostHog snippet (loader only, the rest streams from posthog.com)
  !function(t,e){var o,n,p,r;e.__SV||(window.posthog=e,e._i=[],e.init=function(i,s,a){function g(t,e){var o=e.split(".");2==o.length&&(t=t[o[0]],e=o[1]);t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}}(p=t.createElement("script")).type="text/javascript",p.crossOrigin="anonymous",p.async=!0,p.src=s.api_host.replace(".i.posthog.com","-assets.i.posthog.com")+"/static/array.js",(r=t.getElementsByTagName("script")[0]).parentNode.insertBefore(p,r);var u=e;for(void 0!==a?u=e[a]=[]:a="posthog",u.people=u.people||[],u.toString=function(t){var e="posthog";return"posthog"!==a&&(e+="."+a),t||(e+=" (stub)"),e},u.people.toString=function(){return u.toString(1)+".people (stub)"},o="init capture register register_once register_for_session unregister unregister_for_session getFeatureFlag getFeatureFlagPayload isFeatureEnabled reloadFeatureFlags updateEarlyAccessFeatureEnrollment getEarlyAccessFeatures on onFeatureFlags onSessionId getSurveys getActiveMatchingSurveys renderSurvey canRenderSurvey getNextSurveyStep identify setPersonProperties group resetGroups setPersonPropertiesForFlags resetPersonPropertiesForFlags setGroupPropertiesForFlags resetGroupPropertiesForFlags reset get_distinct_id getGroups get_session_id get_session_replay_url alias set_config startSessionRecording stopSessionRecording sessionRecordingStarted captureException loadToolbar get_property getSessionProperty createPersonProfile opt_in_capturing opt_out_capturing has_opted_in_capturing has_opted_out_capturing clear_opt_in_out_capturing debug getPageViewId".split(" "),n=0;n<o.length;n++)g(u,o[n]);e._i.push([i,s,a])},e.__SV=1)}(document,window.posthog||[]);

  posthog.init(POSTHOG_KEY, {
    api_host: 'https://us.i.posthog.com',
    person_profiles: 'identified_only', // anonymous visitors don't create people rows
    capture_pageview: true,
    capture_pageleave: true,
  });
})();
