<script lang="ts">
    import { onDestroy, type Snippet } from 'svelte';
    import { after, cancel, clamp, register, spring, step, type SimTimer } from '@/shared/lib/spring.svelte';

    /**
     * Loading → Success (порт designbookmark.com/motion#db-loading-success).
     * Кнопка-пилюля: при нажатии надпись уходит вниз, пилюля схлопывается в круг
     * со спиннером; по готовности круг раскрывается в зелёную пилюлю, галочка
     * рисует себя, затем всё возвращается. Пружины 1:1 из оригинала.
     */
    let {
        phase = 'idle',
        children,
        class: className = '',
        ...rest
    }: {
        phase?: 'idle' | 'loading' | 'success';
        children?: Snippet;
        class?: string;
        [key: string]: unknown;
    } = $props();

    const IDLE_W = 150;
    const DOT_W = 36;
    const DONE_W = 150;

    let btnEl: HTMLButtonElement | null = $state(null);
    let txtEl: HTMLSpanElement | null = $state(null);
    let spinEl: HTMLDivElement | null = $state(null);
    let doneEl: HTMLSpanElement | null = $state(null);
    let tickEl: SVGPathElement | null = $state(null);

    const W = spring(IDLE_W, 300, 22);
    const T = spring(1, 400, 30);
    const P = spring(0, 300, 28);
    const D = spring(0, 220, 20);
    const G = spring(0, 200, 30);
    const K = spring(0, 120, 20);
    const len = 22;

    let rot = 0;
    let timers: SimTimer[] = [];
    const later = (s: number, f: () => void): void => {
        const t = after(s, () => {
            timers = timers.filter((x) => x !== t);
            f();
        });
        timers.push(t);
    };

    function go(): void {
        T.t = 0;
        W.t = DOT_W;
        P.t = 1;
    }

    function markDone(): void {
        P.t = 0;
        W.t = DONE_W;
        G.t = 1;
        D.t = 1;
        later(0.15, () => {
            K.t = 1;
        });
        later(3.6, () => {
            D.t = 0;
            G.t = 0;
            K.t = 0;
            W.t = IDLE_W;
            later(0.12, () => {
                T.t = 1;
            });
        });
    }

    $effect(() => {
        if (phase === 'loading') {
            go();
        } else if (phase === 'success') {
            markDone();
        }
    });

    onDestroy(() => {
        timers.forEach(cancel);
        unregister();
    });

    const unregister = register({
        frame(dt) {
            rot += dt * 360 * 1.4;
            [W, T, P, D, G, K].forEach((s) => step(s, dt));
        },
        render() {
            if (!btnEl) return;
            btnEl.style.width = `${W.x.toFixed(2)}px`;
            const g = clamp(G.x, 0, 1);
            btnEl.style.background =
                g > 0.01
                    ? `color-mix(in srgb, var(--color-green-500) ${(g * 100).toFixed(1)}%, var(--color-primary))`
                    : '';
            if (txtEl) {
                txtEl.style.opacity = clamp(T.x, 0, 1).toFixed(3);
                txtEl.style.transform = `translateY(${((1 - T.x) * 14).toFixed(2)}px)`;
            }
            if (spinEl) {
                spinEl.style.opacity = clamp(P.x, 0, 1).toFixed(3);
                spinEl.style.transform = `rotate(${(rot % 360).toFixed(1)}deg) scale(${(0.6 + 0.4 * clamp(P.x, 0, 1.2)).toFixed(3)})`;
            }
            if (doneEl) {
                doneEl.style.opacity = clamp(D.x, 0, 1).toFixed(3);
                doneEl.style.transform = `translateY(${((1 - D.x) * 12).toFixed(2)}px)`;
            }
            if (tickEl) tickEl.style.strokeDashoffset = (len * (1 - clamp(K.x, 0, 1))).toFixed(2);
        },
    });
</script>

<button
    bind:this={btnEl}
    class="bg-primary text-primary-foreground relative h-9 overflow-hidden rounded-full text-sm font-semibold whitespace-nowrap will-change-[width] {className}"
    style="width: {IDLE_W}px"
    {...rest}
>
    <span
        bind:this={txtEl}
        class="absolute inset-0 flex items-center justify-center gap-2 will-change-[transform,opacity]"
    >
        {@render children?.()}
    </span>
    <div
        bind:this={spinEl}
        class="absolute top-1/2 left-1/2 -mt-3 -ml-3 size-6 rounded-full will-change-[transform,opacity]"
        style="background: conic-gradient(from 0deg, rgba(0,0,0,0) 0 10%, var(--color-primary-foreground) 85%, rgba(0,0,0,0) 85%); mask: radial-gradient(circle closest-side, transparent 68%, #000 72%); -webkit-mask: radial-gradient(circle closest-side, transparent 68%, #000 72%); opacity: 0"
    ></div>
    <span
        bind:this={doneEl}
        class="absolute inset-0 flex items-center justify-center gap-2 text-black will-change-[transform,opacity]"
        style="opacity: 0"
    >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" class="size-5">
            <path bind:this={tickEl} d="M5 12.5l4.5 4.5L19 7.5" stroke-dasharray={len} stroke-dashoffset={len} />
        </svg>
        Готово
    </span>
</button>
