<script>
  import AppLayout from '../Layouts/AppLayout.svelte';
  import Button from '../components/ui/button.svelte';
  import Alert from '../components/ui/alert.svelte';
  import { router } from '@inertiajs/svelte';

  export let auth = { isAuthenticated: false, user: null };
  export let app = { name: 'Laravel App', key: null, client_id: null };

  const authPortalUrl = (import.meta.env.VITE_AUTH_BRIDGE_BASE_URL ?? 'http://localhost:8081').replace(/\/$/, '');
  const appKeyParam = app.key ? `?app_key=${app.key}` : '';
  const registerUrl = `${authPortalUrl}/register${appKeyParam}`;

  const goToDashboard = () => router.visit('/dashboard');
  const logout = () => router.post('/logout');

  $: isAuthenticated = auth?.isAuthenticated ?? false;
  $: user = auth?.user ?? null;
  $: userLabel = user?.name ?? user?.email ?? 'your account';
  $: externalUserId = user?.external_user_id ?? '—';
  $: externalAccountId = user?.external_account_id ?? '—';
</script>

<AppLayout title="Welcome">
  {#if isAuthenticated && user}
    <div class="mx-auto max-w-3xl space-y-6">
      <div class="rounded-2xl border border-border bg-card p-8 shadow-sm">
        <div class="space-y-2 text-center">
          <p class="text-xs uppercase tracking-[0.28em] text-muted-foreground">Authenticated</p>
          <h2 class="text-3xl font-semibold">Welcome, {userLabel}!</h2>
          <p class="text-muted-foreground">You are logged in. Here is your user information:</p>
        </div>
        <div class="mt-6 rounded-xl bg-muted p-6 text-left text-sm">
          <div class="flex flex-col gap-2">
            <div><span class="font-semibold text-foreground">Email:</span> {user.email}</div>
            <div><span class="font-semibold text-foreground">External User ID:</span> {externalUserId}</div>
            <div><span class="font-semibold text-foreground">External Account ID:</span> {externalAccountId}</div>
          </div>
        </div>
        <div class="mt-6 flex flex-wrap items-center justify-center gap-3">
          <Button on:click={goToDashboard}>
            Go to dashboard
          </Button>
          <Button variant="secondary" on:click={logout}>
            Log out
          </Button>
        </div>
      </div>
    </div>
  {:else}
    <div class="mx-auto max-w-3xl text-center space-y-6">
      <Alert variant="success">
        <span slot="title">You're ready to sign in</span>
        Onboarding is complete and the Auth Bridge middleware has unlocked the normal homepage.
      </Alert>

      <div class="space-y-4">
        <h2 class="text-3xl font-semibold tracking-tight sm:text-4xl">
          Welcome to your application
        </h2>
        <p class="text-lg text-muted-foreground">
          Sign in with your Auth API account or create a new one to get started.
        </p>
      </div>

      <div class="flex flex-wrap items-center justify-center gap-3">
        <Button href="/login">
          Login
        </Button>
        <Button variant="secondary" href={registerUrl}>
          Register
        </Button>
      </div>
    </div>
  {/if}
</AppLayout>
