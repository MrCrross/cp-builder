<script lang="ts">
    import type { Snippet } from 'svelte';
    import { getContext, onDestroy } from 'svelte';
    import { cubicOut } from 'svelte/easing';
    import { fade, fly } from 'svelte/transition';
    import { cn } from '@/shared/lib/utils';
    import { after, cancel, clamp, register, spring, step, type SimTimer } from '@/shared/lib/spring.svelte';
    import {
        SIDEBAR_CONTEXT,
        SIDEBAR_WIDTH_MOBILE,
        type SidebarContext,
    } from './context';

    let {
        side = 'left',
        variant = 'sidebar',
        collapsible = 'offcanvas',
        class: className = '',
        children,
    }: {
        side?: 'left' | 'right';
        variant?: 'sidebar' | 'floating' | 'inset';
        collapsible?: 'offcanvas' | 'icon' | 'none';
        class?: string;
        children?: Snippet;
    } = $props();

    const { isMobile, state: sidebarState, openMobile, setOpenMobile } = getContext<SidebarContext>(SIDEBAR_CONTEXT);

    // ---- Пружинное сворачивание (порт designbookmark.com/motion#db-sidebar-collapse).
    // Ширина на пружине 260/24, подписи пунктов каскадом — у каждой своя пружина
    // 300/26 с задержкой 45 мс по порядку при раскрытии (при сворачивании все вместе).
    let spacerEl: HTMLDivElement | null = $state(null);
    let panelEl: HTMLDivElement | null = $state(null);

    const EXPANDED_W = 256; // var(--sidebar-width) = 16rem
    const ICON_W = 48; // var(--sidebar-width-icon) = 3rem
    const insetLike = $derived(variant === 'floating' || variant === 'inset');
    const collapsedSpacerW = $derived(insetLike ? ICON_W + 16 : ICON_W);
    const collapsedPanelW = $derived(insetLike ? ICON_W + 16 + 2 : ICON_W);

    const WSpacer = spring(EXPANDED_W, 260, 24);
    const WPanel = spring(EXPANDED_W, 260, 24);

    let labels: { el: HTMLElement; s: ReturnType<typeof spring> }[] = [];
    let labelTimers: SimTimer[] = [];
    let firstSet = true;

    function collectLabels(): void {
        labels = [];
        if (!panelEl) return;
        panelEl.querySelectorAll<HTMLElement>('[data-sidebar="menu-button"]').forEach((btn) => {
            const span = [...btn.children].filter((c): c is HTMLElement => c instanceof HTMLElement && c.tagName === 'SPAN').at(-1);
            if (span) labels.push({ el: span, s: spring(1, 300, 26) });
        });
    }

    function setExpanded(expanded: boolean): void {
        const spacerT = expanded ? EXPANDED_W : collapsedSpacerW;
        const panelT = expanded ? EXPANDED_W : collapsedPanelW;
        WSpacer.t = spacerT;
        WPanel.t = panelT;
        if (firstSet) {
            // первый срабатывание (в т.ч. SSR/гидратация) — без анимации
            WSpacer.x = spacerT;
            WPanel.x = panelT;
            firstSet = false;
        }
        labelTimers.forEach(cancel);
        labelTimers = [];
        collectLabels();
        labels.forEach((l, i) => {
            if (expanded) {
                const t = after(i * 0.045, () => {
                    l.s.t = 1;
                });
                labelTimers.push(t);
            } else {
                l.s.t = 0;
            }
        });
    }

    const unsubscribeState = sidebarState.subscribe((s) => {
        setExpanded(s === 'expanded');
    });

    onDestroy(() => {
        unsubscribeState();
        unregister();
    });

    const unregister = register({
        frame(dt) {
            step(WSpacer, dt);
            step(WPanel, dt);
            labels.forEach((l) => step(l.s, dt));
        },
        render() {
            if (spacerEl) spacerEl.style.width = `${Math.max(0, WSpacer.x).toFixed(2)}px`;
            if (panelEl) panelEl.style.width = `${Math.max(0, WPanel.x).toFixed(2)}px`;
            labels.forEach((l) => {
                const o = clamp(l.s.x, 0, 1);
                l.el.style.opacity = o.toFixed(3);
                l.el.style.transform = `translateX(${((1 - o) * -10).toFixed(2)}px)`;
            });
        },
    });
</script>

{#if collapsible === 'none'}
    <div
        data-slot="sidebar"
        class={cn('bg-sidebar text-sidebar-foreground flex h-full w-(--sidebar-width) flex-col', className)}
    >
        {@render children?.()}
    </div>
{:else if $isMobile}
    {#if $openMobile}
        <div class="fixed inset-0 z-50">
            <button
                type="button"
                class="fixed inset-0 bg-black/50"
                aria-label="Close"
                onclick={() => setOpenMobile(false)}
                transition:fade={{ duration: 200 }}
            ></button>
            <div
                data-sidebar="sidebar"
                data-slot="sidebar"
                data-mobile="true"
                class={cn(
                    'fixed inset-y-0 bg-sidebar text-sidebar-foreground h-svh w-(--sidebar-width) p-0',
                    side === 'left' ? 'left-0 border-r' : 'right-0 border-l',
                    className,
                )}
                style={`--sidebar-width: ${SIDEBAR_WIDTH_MOBILE};`}
                transition:fly={{ x: side === 'left' ? -320 : 320, duration: 300, opacity: 1, easing: cubicOut }}
            >
                <div class="flex h-full w-full flex-col">
                    {@render children?.()}
                </div>
            </div>
        </div>
    {/if}
{:else}
    <div
        class="group peer text-sidebar-foreground hidden md:block"
        data-slot="sidebar"
        data-state={$sidebarState}
        data-collapsible={$sidebarState === 'collapsed' ? collapsible : ''}
        data-variant={variant}
        data-side={side}
    >
        <div
            bind:this={spacerEl}
            class={cn(
                'relative w-(--sidebar-width) bg-transparent',
                'group-data-[collapsible=offcanvas]:w-0',
                'group-data-[side=right]:rotate-180',
                variant === 'floating' || variant === 'inset'
                    ? 'group-data-[collapsible=icon]:w-[calc(var(--sidebar-width-icon)+(--spacing(4)))]'
                    : 'group-data-[collapsible=icon]:w-(--sidebar-width-icon)',
            )}
        ></div>
        <div
            bind:this={panelEl}
            class={cn(
                'fixed inset-y-0 z-10 hidden h-svh w-(--sidebar-width) md:flex',
                side === 'left'
                    ? 'left-0 group-data-[collapsible=offcanvas]:left-[calc(var(--sidebar-width)*-1)]'
                    : 'right-0 group-data-[collapsible=offcanvas]:right-[calc(var(--sidebar-width)*-1)]',
                variant === 'floating' || variant === 'inset'
                    ? 'p-2 group-data-[collapsible=icon]:w-[calc(var(--sidebar-width-icon)+(--spacing(4))+2px)]'
                    : 'group-data-[collapsible=icon]:w-(--sidebar-width-icon) group-data-[side=left]:border-r group-data-[side=right]:border-l',
                className,
            )}
        >
            <div
                data-sidebar="sidebar"
                class="bg-sidebar group-data-[variant=floating]:border-sidebar-border flex h-full w-full flex-col group-data-[variant=floating]:rounded-lg group-data-[variant=floating]:border group-data-[variant=floating]:shadow-sm"
            >
                {@render children?.()}
            </div>
        </div>
    </div>
{/if}
