<script>
  import { Link, page, router } from '@inertiajs/svelte';
  import Button from '../components/ui/button.svelte';
  import Alert from '../components/ui/alert.svelte';

  export let title = '';

  const logout = () => router.post('/logout');

  $: appName = $page.props?.app?.name ?? 'Laravel App';
  $: pageTitle = title ? `${title} · ${appName}` : appName;
  $: flash = $page.props?.flash ?? {};
  $: user = $page.props?.auth?.user ?? null;
</script>

<svelte:head>
  <title>{pageTitle}</title>
</svelte:head>

<div class="min-h-screen bg-background text-foreground">
  <header class="border-b bg-card/70 backdrop-blur">
    <div class="container flex h-16 items-center justify-between gap-6">
      <div class="flex items-center gap-3 text-sm">
        <div class="flex h-10 w-10 items-center justify-center rounded-full bg-primary text-primary-foreground font-semibold uppercase">
          {appName.slice(0, 1)}
        </div>
        <div class="leading-tight">
          <Link href="/">
            <p class="text-[0.7rem] uppercase tracking-[0.28em] text-muted-foreground">Powered by Auth Bridge</p>
            <h1 class="text-lg font-semibold leading-tight">{appName}</h1>
          </Link>
        </div>
      </div>
      <nav class="flex items-center gap-3 text-sm">
        {#if user}
          <span class="hidden text-sm text-muted-foreground md:block">
            {user.name ?? user.email}
          </span>
          <Button variant="secondary" type="button" on:click={logout}>
            Log out
          </Button>
        {:else}
          <Button type="button" on:click={() => router.visit('/login')}>
            Log in
          </Button>
        {/if}
      </nav>
    </div>
  </header>
  <main class="container space-y-6 py-12">
    {#if flash?.success}
      <Alert variant="success">
        <span slot="title">Success</span>
        {flash.success}
      </Alert>
    {/if}
    {#if flash?.error}
      <Alert variant="destructive">
        <span slot="title">Something went wrong</span>
        {flash.error}
      </Alert>
    {/if}
    <slot />
  </main>
</div>
