<script>
  import { cn } from '../../lib/utils';

  export let type = 'button';
  export let variant = 'default';
  export let size = 'default';
  export let disabled = false;
  export let className = '';

  const baseClasses = 'inline-flex items-center justify-center rounded-md text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 ring-offset-background';

  const variants = {
    default: 'bg-primary text-primary-foreground shadow hover:bg-primary/90',
    secondary: 'bg-secondary text-secondary-foreground hover:bg-secondary/90',
    ghost: 'hover:bg-accent hover:text-accent-foreground',
  };

  const sizes = {
    default: 'h-10 px-4 py-2',
    sm: 'h-9 rounded-md px-3',
    lg: 'h-11 rounded-md px-8',
  };
</script>

<button
  {type}
  class={cn(baseClasses, variants[variant] ?? variants.default, sizes[size] ?? sizes.default, className)}
  {disabled}
>
  <slot />
</button>
