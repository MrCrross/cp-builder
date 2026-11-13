/**
 * Порт пружинного движка designbookmark.com/motion (shared/motion.js) на TypeScript.
 * Один rAF-цикл на все компоненты, симуляционные таймеры (замедляются вместе с симуляцией),
 * интеграция Эйлера с субшагами. При prefers-reduced-motion демпфирование становится
 * критическим — пружины не перелетают, движение мягкое и короткое.
 */

export interface Spring {
    x: number;
    v: number;
    t: number;
    k: number;
    c: number;
}

export const spring = (x: number, k = 400, c = 30): Spring => ({
    x,
    v: 0,
    t: x,
    k,
    c,
});

export const tune = (s: Spring, k: number, c: number): void => {
    s.k = k;
    s.c = reducedMotion ? 2 * Math.sqrt(k) : c;
};

export const step = (s: Spring, dt: number): void => {
    const a = -s.k * (s.x - s.t) - s.c * s.v;
    s.v += a * dt;
    s.x += s.v * dt;
};

export const clamp = (v: number, a: number, b: number): number =>
    Math.max(a, Math.min(b, v));

export const reducedMotion =
    typeof matchMedia !== 'undefined' &&
    matchMedia('(prefers-reduced-motion: reduce)').matches;

export interface MotionComponent {
    frame(dt: number): void;
    render(): void;
}

export interface SimTimer {
    t: number;
    fn: () => void;
}

const components = new Set<MotionComponent>();
const timers: SimTimer[] = [];
let rafId: number | null = null;
let last = 0;

function loop(now: number): void {
    const dt = Math.min((now - last) / 1000, 1 / 30);
    last = now;

    for (let i = timers.length - 1; i >= 0; i--) {
        if ((timers[i].t -= dt) <= 0) {
            timers.splice(i, 1)[0].fn();
        }
    }

    const sub = 4;
    for (let k = 0; k < sub; k++) {
        components.forEach((c) => c.frame(dt / sub));
    }
    components.forEach((c) => c.render());

    rafId =
        components.size > 0 || timers.length > 0
            ? requestAnimationFrame(loop)
            : null;
}

function ensureLoop(): void {
    if (rafId === null && typeof requestAnimationFrame !== 'undefined') {
        last = performance.now();
        rafId = requestAnimationFrame(loop);
    }
}

/** Зарегистрировать компонент в общем цикле. Возвращает функцию отмены. */
export function register(component: MotionComponent): () => void {
    components.add(component);
    ensureLoop();
    return () => {
        components.delete(component);
    };
}

/** Таймер на симуляционном времени (секунды), как Motion.after в оригинале. */
export function after(sec: number, fn: () => void): SimTimer {
    const timer: SimTimer = { t: sec, fn };
    timers.push(timer);
    ensureLoop();
    return timer;
}

export function cancel(timer: SimTimer): void {
    const i = timers.indexOf(timer);
    if (i > -1) {
        timers.splice(i, 1);
    }
}
