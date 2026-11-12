<script lang="ts">
    import ErrorScreen from './ErrorScreen.svelte';

    let { status }: { status: number } = $props();
</script>

<ErrorScreen
    {status}
    title="Нет доступа"
    description="У вашей роли нет прав на это действие. Если считаете, что доступ нужен, обратитесь к администратору."
/>
