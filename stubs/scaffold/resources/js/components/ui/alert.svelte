<script>
  import { cn } from '../../lib/utils';

  export let variant = 'default';
  export let className = '';

  const variants = {
    default: 'bg-card text-card-foreground border',
    success: 'bg-emerald-50 text-emerald-900 border-emerald-200',
    destructive: 'text-destructive border-destructive/50 dark:border-destructive [&>svg]:text-destructive',
  };
</script>

<div role="alert" class={cn('relative w-full rounded-lg border p-4 [&>svg~*]:pl-6 [&>svg]:absolute [&>svg]:left-4 [&>svg]:top-4 [&>svg]:text-foreground', variants[variant] ?? variants.default, className)}>
  <slot name="title" let:title>
    <h5 class="mb-1 text-sm font-medium leading-none tracking-tight">{title}</h5>
  </slot>
  <slot />
</div>
