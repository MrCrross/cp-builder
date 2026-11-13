<script lang="ts">
    import ConfirmDialog from './ConfirmDialog.svelte';
    import { getConfirmRequest, resolveConfirm } from './confirm.svelte';

    // Единый хост программных подтверждений — монтируется один раз в
    // AppSidebarLayout, обслуживает все вызовы confirmDialog().
    let open = $state(false);

    const request = $derived(getConfirmRequest());

    $effect(() => {
        if (request) {
            open = true;
        }
    });
</script>

{#if request}
    <ConfirmDialog
        bind:open
        title={request.title}
        description={request.description}
        variant={request.variant}
        confirmText={request.confirmText}
        cancelText={request.cancelText}
        size={request.size}
        onconfirm={() => resolveConfirm(true)}
        oncancel={() => resolveConfirm(false)}
    />
{/if}
