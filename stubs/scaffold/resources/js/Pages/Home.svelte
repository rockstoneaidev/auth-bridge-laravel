<script>
  import AppLayout from '../Layouts/AppLayout.svelte';
  import Button from '../components/ui/button.svelte';
  import Alert from '../components/ui/alert.svelte';
  import { router } from '@inertiajs/svelte';

  export let auth = { isAuthenticated: false, user: null };

  const authPortalUrl = (import.meta.env.VITE_AUTH_BRIDGE_BASE_URL ?? 'http://localhost:8081').replace(/\/$/, '');
  const registerUrl = `${authPortalUrl}/register`;

  const goToDashboard = () => router.visit('/dashboard');
  const logout = () => router.post('/logout');

  $: isAuthenticated = auth?.isAuthenticated ?? false;
  $: userLabel = auth?.user?.name ?? auth?.user?.email ?? 'your account';
</script>

<AppLayout title="Welcome">
  <div class="mx-auto max-w-3xl text-center space-y-6">
    <Alert variant="success">
      <span slot="title">{isAuthenticated ? "You're signed in" : "You're ready to sign in"}</span>
      {#if isAuthenticated}
        Signed in as <span class="font-semibold">{userLabel}</span>. Continue below to open your dashboard or log out.
      {:else}
        Onboarding is complete and the Auth Bridge middleware has unlocked the normal homepage.
      {/if}
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
      {#if isAuthenticated}
        <Button on:click={goToDashboard}>
          Go to dashboard
        </Button>
        <Button variant="secondary" on:click={logout}>
          Log out
        </Button>
      {:else}
        <Button href="/login">
          Login
        </Button>
        <Button variant="secondary" href={registerUrl}>
          Register
        </Button>
      {/if}
    </div>
  </div>
</AppLayout>
