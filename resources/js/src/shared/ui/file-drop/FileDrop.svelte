<script lang="ts">
    import { onDestroy } from 'svelte';
    import { clamp, register, spring, step } from '@/shared/lib/spring.svelte';

    /**
     * File Drop (порт designbookmark.com/motion#db-file-drop).
     * Зона приёма файлов: поднимает заливку, когда файл близко, стрелка качается,
     * при загрузке кольцо заполняется вокруг иконки, в конце — зелёная заливка
     * и рисующаяся галочка. Пружины 1:1 из оригинала.
     */
    let {
        files = $bindable<File[]>([]),
        phase = 'idle',
        progress = 0,
        maxFiles = 5,
        accept = '',
        inputId = '',
        disabled = false,
        idleTitle = 'Перетащите файлы выгрузки',
        idleSubtitle = 'XML, до 5 файлов, один профиль',
    }: {
        files?: File[];
        phase?: 'idle' | 'uploading' | 'done';
        progress?: number;
        maxFiles?: number;
        accept?: string;
        inputId?: string;
        disabled?: boolean;
        idleTitle?: string;
        idleSubtitle?: string;
    } = $props();

    let zoneEl: HTMLDivElement | null = $state(null);
    let coreEl: HTMLDivElement | null = $state(null);
    let greenEl: HTMLDivElement | null = $state(null);
    let ringEl: SVGCircleElement | null = $state(null);
    let ringWrapEl: SVGSVGElement | null = $state(null);
    let upEl: HTMLSpanElement | null = $state(null);
    let pctEl: HTMLSpanElement | null = $state(null);
    let okEl: HTMLSpanElement | null = $state(null);
    let tickEl: SVGPathElement | null = $state(null);
    let titleCurEl: HTMLSpanElement | null = $state(null);
    let titleOldEl: HTMLSpanElement | null = $state(null);
    let subCurEl: HTMLSpanElement | null = $state(null);
    let subOldEl: HTMLSpanElement | null = $state(null);
    let pickEl: HTMLInputElement | null = $state(null);

    const CIRC = 2 * Math.PI * 33;

    // пружины из оригинала
    const ZS = spring(1, 380, 16); // нажатие на зону
    const ZH = spring(0, 300, 26); // наведение с файлом
    const CS = spring(1, 420, 14); // «глок» круга
    const UP = spring(1, 420, 30); // стрелка
    const PO = spring(0, 420, 30); // проценты
    const RO = spring(0, 300, 30); // кольцо
    const G = spring(0, 260, 21); // зелёная заливка
    const K = spring(0, 200, 26); // галочка
    const TO = spring(1, 360, 30); // перекат заголовка
    const SO = spring(1, 360, 30); // перекат подзаголовка

    let title = $state(idleTitle);
    let prevTitle: string | null = $state(null);
    let subtitle = $state(idleSubtitle);
    let prevSubtitle: string | null = $state(null);
    let near = $state(false);
    let overAt = -9;
    let clock = 0;

    const total = $derived(files.reduce((sum, f) => sum + f.size, 0));

    const fmt = (b: number): string =>
        b >= 1e6 ? `${(b / 1e6).toFixed(1)} МБ` : `${Math.max(1, Math.round(b / 1e3))} КБ`;
    const short = (n: string): string => (n.length > 24 ? `${n.slice(0, 12)}…${n.slice(-10)}` : n);

    function sayTitle(text: string): void {
        if (title === text) return;
        prevTitle = title;
        title = text;
        TO.x = 0;
        TO.v = 0;
        TO.t = 1;
    }

    function saySubtitle(text: string): void {
        if (subtitle === text) return;
        prevSubtitle = subtitle;
        subtitle = text;
        SO.x = 0;
        SO.v = 0;
        SO.t = 1;
    }

    function beginUpload(): void {
        UP.t = 0;
        PO.t = 1;
        RO.t = 1;
        G.t = 0;
        K.t = 0;
        CS.x = 1.2;
        ZS.x = 0.965;
        sayTitle('Загружается на сервер');
        saySubtitle(`0 КБ из ${fmt(total)}`);
    }

    function markDone(): void {
        PO.t = 0;
        RO.t = 0;
        G.t = 1;
        K.t = 1;
        CS.x = 0.84;
        sayTitle('Загрузка завершена');
        saySubtitle(
            files.length === 1
                ? `${short(files[0].name)} · ${fmt(files[0].size)}`
                : `${files.length} файла · ${fmt(total)}`,
        );
    }

    function reset(): void {
        G.t = 0;
        K.t = 0;
        UP.t = 1;
        PO.t = 0;
        RO.t = 0;
        sayTitle(idleTitle);
        saySubtitle(files.length > 0 ? filesLabel() : idleSubtitle);
    }

    const filesLabel = (): string =>
        files.length === 1
            ? `${short(files[0].name)} · ${fmt(files[0].size)}`
            : `${files.length} файлов · ${fmt(total)}`;

    $effect(() => {
        if (phase === 'uploading') {
            beginUpload();
        } else if (phase === 'done') {
            markDone();
        } else {
            reset();
        }
    });

    $effect(() => {
        if (phase === 'idle' && files.length > 0) {
            saySubtitle(filesLabel());
        }
    });

    function take(list: FileList | File[] | null): void {
        if (!list || disabled) return;
        files = Array.from(list).slice(0, maxFiles);
        if (pickEl) pickEl.value = '';
    }

    function openPicker(): void {
        if (!disabled) pickEl?.click();
    }

    function roll(cur: HTMLSpanElement | null, old: HTMLSpanElement | null, o: number): void {
        if (!cur) return;
        cur.style.opacity = clamp((o - 0.35) / 0.65, 0, 1).toFixed(3);
        cur.style.transform = `translateY(${((1 - o) * 16).toFixed(2)}px)`;
        if (old) {
            old.style.opacity = clamp(1 - o * 1.8, 0, 1).toFixed(3);
            old.style.transform = `translateY(${(-o * 16).toFixed(2)}px)`;
        }
    }

    const unregister = register({
        frame(dt) {
            clock += dt;
            ZH.t = phase === 'idle' && (near || clock - overAt < 0.2) ? 1 : 0;
            [ZS, ZH, CS, UP, PO, RO, G, K, TO, SO].forEach((s) => step(s, dt));
            if (TO.x > 0.985 && prevTitle) prevTitle = null;
            if (SO.x > 0.985 && prevSubtitle) prevSubtitle = null;
        },
        render() {
            const h = clamp(ZH.x, 0, 1);
            if (zoneEl) {
                zoneEl.style.setProperty('--h', h.toFixed(3));
                zoneEl.style.transform = `scale(${(ZS.x * (1 + 0.025 * ZH.x)).toFixed(4)})`;
            }
            if (coreEl) {
                coreEl.style.transform = `translateY(${(-5 * ZH.x).toFixed(2)}px) scale(${CS.x.toFixed(4)})`;
            }
            if (greenEl) greenEl.style.transform = `scale(${Math.max(0, G.x).toFixed(4)})`;
            if (ringWrapEl) ringWrapEl.style.opacity = clamp(RO.x, 0, 1).toFixed(3);
            if (ringEl) ringEl.style.strokeDashoffset = (CIRC * (1 - clamp(progress, 0, 1))).toFixed(2);
            if (upEl) {
                const bob = -3.5 * h * (0.5 + 0.5 * Math.sin(clock * 7));
                upEl.style.opacity = clamp(UP.x, 0, 1).toFixed(3);
                upEl.style.transform = `translateY(${(bob + (1 - UP.x) * -12).toFixed(2)}px) scale(${(0.6 + 0.4 * UP.x).toFixed(3)})`;
            }
            if (pctEl) {
                pctEl.style.opacity = clamp(PO.x, 0, 1).toFixed(3);
                pctEl.style.transform = `scale(${(0.7 + 0.3 * PO.x).toFixed(3)})`;
                const n = String(Math.round(clamp(progress, 0, 1) * 100));
                if (pctEl.textContent !== n) pctEl.textContent = n;
            }
            if (okEl) okEl.style.opacity = clamp(K.x * 4, 0, 1).toFixed(3);
            if (tickEl) tickEl.style.strokeDashoffset = (24 * (1 - clamp(K.x, 0, 1))).toFixed(2);
            roll(titleCurEl, titleOldEl, clamp(TO.x, 0, 1));
            roll(subCurEl, subOldEl, clamp(SO.x, 0, 1));
            if (phase === 'uploading' && subCurEl) {
                const text = `${fmt(clamp(progress, 0, 1) * total)} из ${fmt(total)}`;
                if (subCurEl.textContent !== text) subCurEl.textContent = text;
            }
        },
    });

    onDestroy(() => unregister());
</script>

<div class="flex flex-col gap-3">
    <!-- svelte-ignore a11y_no_noninteractive_element_to_interactive_role -->
    <div
        bind:this={zoneEl}
        class="bg-card relative h-54 w-full max-w-74 cursor-pointer overflow-hidden rounded-xl will-change-transform select-none"
        class:opacity-60={disabled}
        role="button"
        tabindex={disabled ? -1 : 0}
        aria-label="Загрузить файлы выгрузки: перетащите сюда или нажмите Enter, чтобы выбрать"
        data-test="file-drop"
        ondragover={(event) => {
            event.preventDefault();
            overAt = clock;
        }}
        ondragleave={() => {
            near = false;
        }}
        ondragenter={() => {
            near = true;
        }}
        ondrop={(event) => {
            event.preventDefault();
            near = false;
            take(event.dataTransfer?.files ?? null);
        }}
        onclick={() => openPicker()}
        onkeydown={(event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                openPicker();
            }
        }}
    >
        <div
            class="bg-accent/60 pointer-events-none absolute inset-0 rounded-xl opacity-[var(--h,0)]"
        ></div>
        <div
            bind:this={coreEl}
            class="bg-accent absolute top-9 left-1/2 z-1 -ml-9 size-[72px] rounded-full will-change-transform"
        >
            <div
                bind:this={greenEl}
                class="bg-green-500 absolute inset-0 rounded-full will-change-transform"
                style="transform: scale(0)"
            ></div>
            <svg
                bind:this={ringWrapEl}
                class="absolute inset-0 size-full -rotate-90"
                viewBox="0 0 72 72"
                fill="none"
                stroke-width="3"
                stroke-linecap="round"
                style="opacity: 0"
            >
                <circle cx="36" cy="36" r="33" stroke="var(--color-border)" />
                <circle
                    bind:this={ringEl}
                    cx="36"
                    cy="36"
                    r="33"
                    stroke="currentColor"
                    class="text-foreground"
                    stroke-dasharray={CIRC.toFixed(2)}
                    stroke-dashoffset={CIRC.toFixed(2)}
                />
            </svg>
            <span
                bind:this={upEl}
                class="text-foreground absolute inset-0 grid place-items-center will-change-transform"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="size-6">
                    <path d="M12 19V5M5.5 11.5 12 5l6.5 6.5" />
                </svg>
            </span>
            <span
                bind:this={pctEl}
                class="text-foreground absolute inset-0 grid place-items-center text-lg font-semibold tracking-tight tabular-nums will-change-transform"
                style="opacity: 0"
            >0</span>
            <span
                bind:this={okEl}
                class="absolute inset-0 grid place-items-center text-black will-change-transform"
                style="opacity: 0"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" class="size-7">
                    <path
                        bind:this={tickEl}
                        d="M5 12.5l4.5 4.5L19 7.5"
                        stroke-dasharray="24"
                        stroke-dashoffset="24"
                    />
                </svg>
            </span>
        </div>
        <div class="pointer-events-none absolute top-[130px] right-4 left-4 z-1 h-6 overflow-hidden text-center">
            {#if prevTitle !== null}
                <span
                    bind:this={titleOldEl}
                    class="absolute inset-x-0 top-0 overflow-hidden text-base leading-6 font-semibold tracking-tight whitespace-nowrap text-ellipsis will-change-transform"
                >{prevTitle}</span>
            {/if}
            <span
                bind:this={titleCurEl}
                class="absolute inset-x-0 top-0 overflow-hidden text-base leading-6 font-semibold tracking-tight whitespace-nowrap text-ellipsis will-change-transform"
            >{title}</span>
        </div>
        <div class="pointer-events-none absolute top-[157px] right-4 left-4 z-1 h-5 overflow-hidden text-center">
            {#if prevSubtitle !== null}
                <span
                    bind:this={subOldEl}
                    class="text-muted-foreground absolute inset-x-0 top-0 overflow-hidden text-[13px] leading-5 whitespace-nowrap text-ellipsis tabular-nums will-change-transform"
                >{prevSubtitle}</span>
            {/if}
            <span
                bind:this={subCurEl}
                class="text-muted-foreground absolute inset-x-0 top-0 overflow-hidden text-[13px] leading-5 whitespace-nowrap text-ellipsis tabular-nums will-change-transform"
            >{subtitle}</span>
        </div>
        <input
            bind:this={pickEl}
            id={inputId || undefined}
            type="file"
            {accept}
            multiple={maxFiles > 1}
            hidden
            tabindex="-1"
            aria-hidden="true"
            onchange={() => take(pickEl?.files ?? null)}
        />
    </div>

    {#if files.length > 0 && phase === 'idle'}
        <ul class="flex flex-wrap gap-2">
            {#each files as file (file.name + file.size)}
                <li class="bg-accent text-accent-foreground flex h-8 items-center gap-2 rounded-md px-3 text-xs font-medium whitespace-nowrap">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" class="size-4">
                        <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" />
                        <path d="M14 3v5h5" />
                    </svg>
                    {short(file.name)}
                    <button
                        type="button"
                        class="opacity-60 hover:opacity-100"
                        aria-label="Убрать файл {file.name}"
                        onclick={(event) => {
                            event.stopPropagation();
                            files = files.filter((f) => f !== file);
                        }}
                    >✕</button>
                </li>
            {/each}
        </ul>
    {/if}
</div>
