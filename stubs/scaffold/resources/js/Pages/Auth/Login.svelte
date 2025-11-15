<script>
  import Button from '../../components/ui/button.svelte';

  const authPortalUrl = (import.meta.env.VITE_AUTH_BRIDGE_BASE_URL ?? 'http://localhost:8081').replace(/\/$/, '');
  const registerUrl = `${authPortalUrl}/register`;
</script>

<div class="flex min-h-screen items-center justify-center bg-background p-6 text-foreground">
  <div class="w-full max-w-md space-y-6 rounded-xl border bg-card p-8 shadow-sm">
    <div class="space-y-2 text-center">
      <p class="text-xs uppercase tracking-[0.28em] text-muted-foreground">Powered by Auth Bridge</p>
      <h1 class="text-2xl font-semibold tracking-tight">Sign in to continue</h1>
      <p class="text-sm text-muted-foreground">
        You will be redirected to the Auth Portal where you can use email/password, Google, and MFA.
      </p>
    </div>

    <div class="space-y-3">
      <Button className="w-full" size="lg" href="/oauth/redirect">
        Continue to Auth Portal
      </Button>
      <p class="text-center text-sm text-muted-foreground">
        Need an account?
        <a class="font-medium text-primary hover:underline" href={registerUrl} rel="noreferrer">
          Create one in the Auth Portal
        </a>
      </p>
    </div>
  </div>
</div>
