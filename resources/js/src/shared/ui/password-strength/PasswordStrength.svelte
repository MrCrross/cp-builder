<script lang="ts">
    import { onDestroy } from 'svelte';
    import { after, cancel, clamp, register, spring, step, type SimTimer } from '@/shared/lib/spring.svelte';

    /**
     * Password Strength (порт designbookmark.com/motion#db-password-strength).
     * Оценка (длина, регистры, цифра, символ) задаёт, сколько из четырёх полос
     * заполнено; у каждой полосы своя пружина ширины 260/24, запускаются они
     * с задержкой 60 мс друг за другом. Цвет идёт красный → оранжевый → жёлтый
     * → зелёный, слово под полями меняется с подъёмом 300/24.
     */
    let {
        value = '',
        class: className = '',
    }: {
        value?: string;
        class?: string;
    } = $props();

    const COLORS = [
        'var(--color-red-500)',
        'var(--color-amber-500)',
        'var(--color-yellow-400)',
        'var(--color-green-500)',
    ];
    const WORDS = ['Слишком короткий', 'Слабый', 'Средний', 'Хороший', 'Надёжный'];

    let barEls: HTMLElement[] = [];
    let wordEl: HTMLElement | null = $state(null);

    const bars = Array.from({ length: 4 }, () => spring(0, 260, 24));
    const WD = spring(1, 300, 24);

    let score = $state(-1);
    let timers: SimTimer[] = [];

    function rate(pwd: string): number {
        if (pwd.length < 6) return 0;
        let s = 1;
        if (pwd.length >= 8 && /[a-zа-яё]/.test(pwd) && /[A-ZА-ЯЁ]/.test(pwd)) s++;
        if (/\d/.test(pwd)) s++;
        if (/[^A-Za-zА-ЯЁа-яё0-9]/.test(pwd) && pwd.length >= 10) s++;
        return s;
    }

    function update(pwd: string): void {
        const s = rate(pwd);
        if (s === score) return;
        score = s;
        timers.forEach(cancel);
        timers = [];
        bars.forEach((b, i) => {
            const t = after(i * 0.06, () => {
                b.t = i < score ? 1 : 0;
            });
            timers.push(t);
        });
        WD.x = 0;
        WD.v = 0;
        WD.t = 1;
    }

    $effect(() => {
        update(value);
    });

    onDestroy(() => {
        timers.forEach(cancel);
        unregister();
    });

    const unregister = register({
        frame(dt) {
            bars.forEach((b) => step(b, dt));
            step(WD, dt);
        },
        render() {
            const col = COLORS[clamp(score - 1, 0, 3)];
            bars.forEach((b, i) => {
                const el = barEls[i];
                if (!el) return;
                el.style.width = `${(clamp(b.x, 0, 1.05) * 100).toFixed(2)}%`;
                el.style.background = col;
            });
            if (wordEl) {
                wordEl.style.color = score > 0 && value ? col : 'var(--color-muted-foreground)';
                wordEl.style.opacity = clamp(WD.x, 0, 1).toFixed(3);
                wordEl.style.transform = `translateY(${((1 - clamp(WD.x, 0, 1)) * 6).toFixed(2)}px)`;
            }
        },
    });

    const label = $derived(value ? WORDS[clamp(score, 0, 4)] : WORDS[0]);
</script>

<div class={className} aria-live="polite">
    <div class="mt-2 flex gap-1.5">
        {#each bars as _, i (i)}
            <div class="bg-muted relative h-[5px] flex-1 overflow-hidden rounded-[3px]">
                <div
                    bind:this={barEls[i]}
                    class="absolute top-0 bottom-0 left-0 rounded-[3px] will-change-[width]"
                    style="width: 0"
                ></div>
            </div>
        {/each}
    </div>
    <div class="mt-2.5 flex items-center justify-between text-xs">
        <span
            bind:this={wordEl}
            class="font-semibold will-change-[transform,opacity]"
        >{label}</span>
        <span class="text-muted-foreground">от 8 символов, разные регистры, цифра, символ</span>
    </div>
</div>
