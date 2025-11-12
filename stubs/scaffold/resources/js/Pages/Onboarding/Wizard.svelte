<script>
  import AppLayout from '../../Layouts/AppLayout.svelte';
  import Alert from '../../components/ui/alert.svelte';
  import Button from '../../components/ui/button.svelte';
  import Input from '../../components/ui/input.svelte';
  import Label from '../../components/ui/label.svelte';
  import { router } from '@inertiajs/svelte';

  let step = 1;
  let loading = false;
  let error = '';

  // Step 1: Auth API credentials
  let authBase = 'http://auth_api/api/v1';
  let browserUrl = 'http://localhost:8081/api/v1';
  let email = '';
  let password = '';
  let totp = '';
  let bootstrapToken = '';

  // Step 2: App configuration
  let appName = '';
  let appKey = '';
  let redirect = 'http://localhost/oauth/callback';
  let accounts = '1';
  let clientId = '';
  let clientSecret = '';

  async function getBootstrapToken() {
    loading = true;
    error = '';

    try {
      const response = await fetch('/onboarding/token', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        },
        body: JSON.stringify({
          base_url: authBase,
          email,
          password,
          totp: totp || null,
        }),
      });

      const data = await response.json();

      if (!response.ok) {
        error = data.message || 'Failed to get bootstrap token';
        return;
      }

      bootstrapToken = data.token;
      step = 2;
    } catch (e) {
      error = 'Network error: ' + e.message;
    } finally {
      loading = false;
    }
  }

  async function runOnboarding() {
    loading = true;
    error = '';

    try {
      const response = await fetch('/onboarding/run', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        },
        body: JSON.stringify({
          auth_base: authBase,
          token: bootstrapToken,
          app_name: appName,
          app_key: appKey,
          redirect,
          accounts,
          client_id: clientId || null,
          client_secret: clientSecret || null,
        }),
      });

      const data = await response.json();

      if (!response.ok) {
        error = data.message || 'Onboarding failed';
        if (data.output) {
          console.error('Onboarding output:', data.output);
        }
        return;
      }

      // Success! Redirect to home
      router.visit('/');
    } catch (e) {
      error = 'Network error: ' + e.message;
    } finally {
      loading = false;
    }
  }

  function goBack() {
    step = 1;
    error = '';
  }
</script>

<AppLayout title="App Onboarding">
  <div class="mx-auto max-w-2xl space-y-6">
    <div class="text-center">
      <h1 class="text-3xl font-bold">App Onboarding</h1>
      <p class="text-muted-foreground mt-2">
        Configure your application to connect with the Auth API
      </p>
    </div>

    {#if error}
      <Alert variant="destructive">
        <span slot="title">Error</span>
        {error}
      </Alert>
    {/if}

    {#if step === 1}
      <div class="rounded-lg border border-border bg-card p-6 space-y-4">
        <div>
          <h2 class="text-xl font-semibold mb-4">Step 1: Authenticate with Auth API</h2>
          <p class="text-sm text-muted-foreground mb-4">
            Enter your Auth API super admin credentials to obtain a bootstrap token.
          </p>
        </div>

        <div class="space-y-4">
          <div class="grid gap-2">
            <Label for="authBase">Auth API Base URL (Internal)</Label>
            <Input
              id="authBase"
              type="url"
              bind:value={authBase}
              placeholder="http://auth_api/api/v1"
              disabled={loading}
            />
            <p class="text-xs text-muted-foreground">
              Server-to-server URL (e.g., Docker internal hostname)
            </p>
          </div>

          <div class="grid gap-2">
            <Label for="browserUrl">Auth API URL (Browser Access)</Label>
            <Input
              id="browserUrl"
              type="url"
              bind:value={browserUrl}
              placeholder="http://localhost:8081/api/v1"
              disabled={loading}
            />
            <p class="text-xs text-muted-foreground">
              Public URL for browser OAuth redirects
            </p>
          </div>

          <div class="grid gap-2">
            <Label for="email">Super Admin Email</Label>
            <Input
              id="email"
              type="email"
              bind:value={email}
              placeholder="admin@example.com"
              disabled={loading}
            />
          </div>

          <div class="grid gap-2">
            <Label for="password">Super Admin Password</Label>
            <Input
              id="password"
              type="password"
              bind:value={password}
              placeholder="••••••••"
              disabled={loading}
            />
          </div>

          <div class="grid gap-2">
            <Label for="totp">Two-Factor Code (Optional)</Label>
            <Input
              id="totp"
              type="text"
              bind:value={totp}
              placeholder="123456"
              disabled={loading}
            />
          </div>
        </div>

        <Button on:click={getBootstrapToken} disabled={loading} class="w-full">
          {loading ? 'Authenticating...' : 'Get Bootstrap Token'}
        </Button>
      </div>
    {/if}

    {#if step === 2}
      <div class="rounded-lg border border-border bg-card p-6 space-y-4">
        <div>
          <h2 class="text-xl font-semibold mb-4">Step 2: Configure Application</h2>
          <p class="text-sm text-muted-foreground mb-4">
            Set up your application details and OAuth configuration.
          </p>
        </div>

        <div class="space-y-4">
          <div class="grid gap-2">
            <Label for="appName">Application Name</Label>
            <Input
              id="appName"
              type="text"
              bind:value={appName}
              placeholder="My Laravel App"
              disabled={loading}
            />
          </div>

          <div class="grid gap-2">
            <Label for="appKey">Application Key (slug)</Label>
            <Input
              id="appKey"
              type="text"
              bind:value={appKey}
              placeholder="my-app"
              disabled={loading}
            />
            <p class="text-xs text-muted-foreground">
              Lowercase letters, numbers, and hyphens only
            </p>
          </div>

          <div class="grid gap-2">
            <Label for="redirect">OAuth Redirect URI</Label>
            <Input
              id="redirect"
              type="url"
              bind:value={redirect}
              placeholder="http://localhost/oauth/callback"
              disabled={loading}
            />
          </div>

          <div class="grid gap-2">
            <Label for="accounts">Default Account IDs</Label>
            <Input
              id="accounts"
              type="text"
              bind:value={accounts}
              placeholder="1"
              disabled={loading}
            />
            <p class="text-xs text-muted-foreground">
              Comma-separated account IDs (e.g., "1" or "1,2,3")
            </p>
          </div>

          <div class="border-t pt-4">
            <p class="text-sm font-medium mb-2">
              Optional: Use Existing OAuth Client
            </p>
            <p class="text-xs text-muted-foreground mb-4">
              Leave blank to create a new OAuth client automatically
            </p>

            <div class="space-y-4">
              <div class="grid gap-2">
                <Label for="clientId">OAuth Client ID</Label>
                <Input
                  id="clientId"
                  type="text"
                  bind:value={clientId}
                  placeholder="(optional)"
                  disabled={loading}
                />
              </div>

              <div class="grid gap-2">
                <Label for="clientSecret">OAuth Client Secret</Label>
                <Input
                  id="clientSecret"
                  type="password"
                  bind:value={clientSecret}
                  placeholder="(optional)"
                  disabled={loading}
                />
              </div>
            </div>
          </div>
        </div>

        <div class="flex gap-2">
          <Button variant="outline" on:click={goBack} disabled={loading}>
            Back
          </Button>
          <Button on:click={runOnboarding} disabled={loading} class="flex-1">
            {loading ? 'Running Onboarding...' : 'Complete Onboarding'}
          </Button>
        </div>
      </div>
    {/if}
  </div>
</AppLayout>
