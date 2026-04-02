<template>
  <main class="min-h-screen bg-bg-deep px-6 py-10 text-text-primary">
    <div class="mx-auto max-w-6xl space-y-6">
      <header class="flex items-end justify-between gap-4 rounded-lg border border-border bg-bg-panel p-6">
        <div>
          <p class="font-mono text-sm uppercase tracking-[0.3em] text-accent">Framecast</p>
          <h1 class="mt-3 text-4xl font-semibold">Phase 0 workspace shell</h1>
          <p class="mt-2 max-w-2xl text-sm text-text-secondary">
            Router, auth store, Axios refresh flow, and Reverb client wiring are in place. Domain flows come next.
          </p>
        </div>
      </header>

      <section class="grid gap-4 md:grid-cols-3">
        <article class="rounded-lg border border-border bg-bg-card p-5">
          <p class="text-sm text-text-secondary">API</p>
          <h2 class="mt-2 text-xl font-semibold">Laravel 11</h2>
          <p class="mt-2 text-sm text-text-muted">Versioned JSON routing, Horizon, Reverb, Redis, and B2 disk config are scaffolded.</p>
        </article>

        <article class="rounded-lg border border-border bg-bg-card p-5">
          <p class="text-sm text-text-secondary">Web</p>
          <h2 class="mt-2 text-xl font-semibold">Vue 3 + Vite</h2>
          <p class="mt-2 text-sm text-text-muted">Pinia auth state, route guards, Axios interceptor, and Echo bootstrap are ready for real auth endpoints.</p>
        </article>

        <article class="rounded-lg border border-border bg-bg-card p-5">
          <p class="text-sm text-text-secondary">Infra</p>
          <h2 class="mt-2 text-xl font-semibold">Compose stack</h2>
          <p class="mt-2 text-sm text-text-muted">API, worker, scheduler, reverb, horizon, postgres, redis, and web services are defined at the monorepo root.</p>
        </article>
      </section>
    </div>
  </main>
</template>
