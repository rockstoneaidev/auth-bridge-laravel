<script>
  import { cn } from '../../lib/utils';

  export let type = 'text';
  export let value = '';
  export let className = '';
  export let placeholder = '';
  export let id;
  export let name;
  export let required = false;
  export let autocomplete;
</script>

<input
  {type}
  bind:value
  {placeholder}
  {id}
  {name}
  {required}
  {autocomplete}
  class={cn(
    'flex h-10 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50',
    className
  )}
/>
