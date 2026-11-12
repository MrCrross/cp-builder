<script lang="ts">
    import ErrorScreen from './ErrorScreen.svelte';

    let { status }: { status: number } = $props();
</script>

<ErrorScreen
    {status}
    title="Ошибка сервера"
    description="Что-то пошло не так. Попробуйте повторить действие позже; если ошибка повторяется, сообщите администратору."
/>
