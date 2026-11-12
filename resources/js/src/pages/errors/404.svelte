<script lang="ts">
    import ErrorScreen from './ErrorScreen.svelte';

    let { status }: { status: number } = $props();
</script>

<ErrorScreen
    {status}
    title="Страница не найдена"
    description="Такой страницы не существует или ссылка устарела."
/>
