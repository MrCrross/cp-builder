<script lang="ts">
    import { onDestroy } from 'svelte';
    import { clamp, register, spring, step } from '@/shared/lib/spring.svelte';

    /**
     * Toggle Switch (порт designbookmark.com/motion#db-toggle-switch).
     * Ручка тянется по направлению движения, затем округляется и слегка
     * подпрыгивает на посадке, трек заливается зелёным. Пружины 1:1 из оригинала:
     * позиция 320/18, растяжение 300/20.
     */
    let {
        checked = $bindable(false),
        id = '',
        disabled = false,
        class: className = '',
        ...rest
    }: {
        checked?: boolean;
        id?: string;
        disabled?: boolean;
        class?: string;
        [key: string]: unknown;
    } = $props();

    let trackEl: HTMLButtonElement | null = $state(null);
    let knobEl: HTMLDivElement | null = $state(null);

    const p = spring(checked ? 1 : 0, 320, 18); // позиция 0..1
    const w = spring(0, 300, 20); // растяжение ручки

    // синхронизация внешнего checked с пружиной
    $effect(() => {
        p.t = checked ? 1 : 0;
    });

    function flip(): void {
        if (disabled) return;
        checked = !checked;
        w.t = 0;
        w.v = 60; // импульс растяжения из оригинала
    }

    onDestroy(() => unregister());

    const unregister = register({
        frame(dt) {
            step(p, dt);
            step(w, dt);
        },
        render() {
            if (!trackEl || !knobEl) return;
            // ручка растёт вперёд по ходу; передняя кромка опережает
            const grow = clamp(w.x, 0, 1) * 10;
            const x = p.x * 20;
            knobEl.style.width = `${(26 + grow).toFixed(2)}px`;
            knobEl.style.transform = `translateX(${(checked ? x - grow * clamp(p.x, 0, 1) : x).toFixed(2)}px)`;
            const pos = clamp(p.x, 0, 1);
            trackEl.style.background = `color-mix(in srgb, var(--color-green-500) ${(pos * 100).toFixed(1)}%, var(--color-input))`;
        },
    });
</script>

<button
    bind:this={trackEl}
    {id}
    type="button"
    role="switch"
    aria-checked={checked}
    class="bg-input relative h-8 w-[52px] flex-none cursor-pointer rounded-full border-0 p-0 disabled:cursor-not-allowed disabled:opacity-50 {className}"
    onclick={flip}
    {disabled}
    {...rest}
>
    <div
        bind:this={knobEl}
        class="bg-background absolute top-[3px] left-[3px] h-[26px] w-[26px] rounded-full will-change-[transform,width]"
    ></div>
</button>
