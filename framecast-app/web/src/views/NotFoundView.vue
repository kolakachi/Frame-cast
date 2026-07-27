<script setup>
import { RouterLink } from "vue-router";
</script>

<template>
  <main class="nf">
    <div class="nf-logo" aria-label="WyvStudio">
      <svg width="28" height="28" viewBox="0 0 64 64" fill="none" aria-hidden="true">
        <rect width="64" height="64" rx="14" fill="#ff6b35" />
        <path d="M14 18 L22 46 L32 28 L42 46 L50 18" stroke="white" stroke-width="4.5"
          stroke-linecap="round" stroke-linejoin="round" fill="none" />
        <path d="M27 37 Q32 28 37 37" stroke="rgba(255,255,255,0.35)" stroke-width="2.5"
          stroke-linecap="round" fill="none" />
      </svg>
      <span class="nf-wordmark"><b>Wyv</b>Studio</span>
    </div>

    <div class="nf-code">404</div>
    <h1 class="nf-title">This page didn't make the final cut.</h1>
    <p class="nf-sub">The link is broken or the page has moved. Let's get you back to the studio.</p>

    <div class="nf-actions">
      <RouterLink to="/dashboard" class="nf-btn nf-btn-primary">Back to dashboard</RouterLink>
      <a href="https://wyvstudio.com" class="nf-btn nf-btn-ghost">Go to homepage</a>
    </div>
  </main>
</template>

<style scoped>
.nf {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
  padding: 32px 24px;
  background:
    radial-gradient(circle at 50% 0%, rgba(255, 107, 53, 0.10), transparent 45%),
    var(--color-bg-deep);
  font-family: var(--font-sans);
}
.nf-logo { display: inline-flex; align-items: center; gap: 10px; margin-bottom: 56px; }
.nf-wordmark { font-size: 15px; font-weight: 700; color: var(--color-text-primary); letter-spacing: -0.3px; }
.nf-wordmark b { color: var(--color-accent); }
.nf-code {
  font-family: var(--font-mono);
  font-size: clamp(96px, 22vw, 168px);
  font-weight: 700;
  line-height: 0.9;
  letter-spacing: -0.03em;
  color: var(--color-accent);
  text-shadow: 0 12px 60px rgba(255, 107, 53, 0.35);
}
.nf-title { font-size: clamp(22px, 5vw, 30px); font-weight: 700; letter-spacing: -0.02em; margin-top: 28px; text-wrap: balance; }
.nf-sub { color: var(--color-text-secondary); font-size: 16px; line-height: 1.6; margin-top: 14px; max-width: 30rem; }
.nf-actions { display: flex; flex-wrap: wrap; gap: 12px; justify-content: center; margin-top: 36px; }
.nf-btn {
  display: inline-flex; align-items: center; padding: 12px 22px; border-radius: 12px;
  font-size: 15px; font-weight: 600; text-decoration: none;
  transition: transform 0.12s ease, background 0.15s ease, border-color 0.15s ease;
}
.nf-btn-primary { background: var(--color-accent); color: #fff; }
.nf-btn-primary:hover { background: var(--color-accent-hover); transform: translateY(-1px); }
.nf-btn-ghost { color: var(--color-text-primary); border: 1px solid var(--color-border); }
.nf-btn-ghost:hover { border-color: var(--color-border-active); transform: translateY(-1px); }
@media (prefers-reduced-motion: reduce) {
  .nf-btn { transition: none; }
  .nf-btn:hover { transform: none; }
}
</style>
