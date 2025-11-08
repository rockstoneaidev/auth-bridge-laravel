<script>
  import { cn } from '../../lib/utils';

  export let forId = undefined;
  export let className = '';
</script>

<label for={forId} class={cn('text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70', className)}>
  <slot />
</label>
