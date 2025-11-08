<script>
  import AppLayout from '../Layouts/AppLayout.svelte';
  import Button from '../components/ui/button.svelte';
  import Alert from '../components/ui/alert.svelte';
  import { router } from '@inertiajs/svelte';

  const goToLogin = () => router.visit('/login');
</script>

<AppLayout title="Welcome">
  <div class="mx-auto max-w-3xl text-center space-y-6">
    <Alert variant="success">
      <span slot="title">Auth Bridge installed</span>
      Your Laravel app now delegates authentication, OAuth client management, and user context to the centralized Auth API.
    </Alert>

    <div class="space-y-4">
      <h2 class="text-3xl font-semibold tracking-tight sm:text-4xl">
        Ship product features instead of wiring auth from scratch.
      </h2>
      <p class="text-lg text-muted-foreground">
        Use the Auth Bridge middleware, controller, and frontend helpers that shipped with this scaffold to handle login, logout, and account-aware API calls.
      </p>
    </div>

    <div class="flex flex-wrap items-center justify-center gap-3">
      <Button on:click={goToLogin}>
        Connect with Auth API
      </Button>
      <Button variant="secondary" on:click={() => router.visit('/me')}>
        View current user
      </Button>
    </div>
  </div>
</AppLayout>
