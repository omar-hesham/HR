(() => {
    const register = () => {
        Alpine.data('recruitDemoBanner', () => ({
            observer: null,
            init() {
                this.observer = new ResizeObserver(() => {
                    document.documentElement.style.setProperty('--recruit-demo-height', `${this.$el.offsetHeight}px`);
                });
                this.observer.observe(this.$el);
            },
            destroy() {
                this.observer?.disconnect();
                document.documentElement.style.removeProperty('--recruit-demo-height');
            },
        }));

        Alpine.data('recruitGuidedTour', (wire, steps, counterTemplate) => ({
            steps, index: 0, active: true, busy: false, failed: false, spotlight: false,
            target: null, frame: null, observer: null, reposition: null, motion: null,
            previousFocus: null,
            get step() { return this.steps[this.index]; },
            get counter() { return counterTemplate.replace(':current', String(this.index + 1)).replace(':total', String(this.steps.length)); },
            get reduced() { return this.motion.matches; },
            init() {
                this.previousFocus = document.activeElement;
                this.motion = window.matchMedia('(prefers-reduced-motion: reduce)');
                this.reposition = () => {
                    cancelAnimationFrame(this.frame);
                    this.frame = requestAnimationFrame(() => this.position());
                };
                window.addEventListener('resize', this.reposition);
                window.addEventListener('scroll', this.reposition, true);
                this.observer = new ResizeObserver(this.reposition);
                this.observer.observe(this.$refs.card);
                this.$nextTick(() => this.showStep());
            },
            findTarget() {
                if (!this.step.target) return null;
                return [...document.querySelectorAll(this.step.target)].find(element => {
                    const rect = element.getBoundingClientRect();
                    const style = getComputedStyle(element);
                    return rect.width > 0 && rect.height > 0 && rect.right > 0 && rect.left < innerWidth && style.visibility !== 'hidden' && style.display !== 'none';
                }) ?? null;
            },
            showStep() {
                this.failed = false;
                this.target = this.findTarget();
                if (this.target) {
                    this.target.scrollIntoView({ block: 'center', inline: 'nearest', behavior: this.reduced ? 'instant' : 'smooth' });
                }
                this.$nextTick(() => {
                    this.position();
                    this.$refs.heading.focus({ preventScroll: true });
                    if (!this.reduced) {
                        this.$refs.card.animate([
                            { opacity: 0, translate: '0 8px' },
                            { opacity: 1, translate: '0 0' },
                        ], { duration: 220, easing: 'ease-out' });
                    }
                });
            },
            position() {
                if (!this.active || !this.$refs.card) return;
                const margin = 16;
                const width = this.$refs.card.offsetWidth;
                const height = this.$refs.card.offsetHeight;
                const clamp = (n, min, max) => Math.max(min, Math.min(n, Math.max(min, max)));
                let left = (innerWidth - width) / 2;
                let top = (innerHeight - height) / 2;
                this.spotlight = false;
                if (this.target?.isConnected) {
                    const rect = this.target.getBoundingClientRect();
                    const x = Math.max(4, rect.left - 6);
                    const y = Math.max(4, rect.top - 6);
                    const right = Math.min(innerWidth - 4, rect.right + 6);
                    const bottom = Math.min(innerHeight - 4, rect.bottom + 6);
                    if (right > x && bottom > y) {
                        this.spotlight = true;
                        Object.assign(this.$refs.spotlight.style, { left: `${x}px`, top: `${y}px`, width: `${right - x}px`, height: `${bottom - y}px` });
                        if (innerWidth - right >= width + margin * 2) {
                            left = right + margin;
                            top = y;
                        } else if (x >= width + margin * 2) {
                            left = x - width - margin;
                            top = y;
                        } else if (innerHeight - bottom >= height + margin * 2) {
                            top = bottom + margin;
                        } else if (y >= height + margin * 2) {
                            top = y - height - margin;
                        }
                    }
                }
                Object.assign(this.$refs.card.style, {
                    left: `${clamp(left, margin, innerWidth - width - margin)}px`,
                    top: `${clamp(top, margin, innerHeight - height - margin)}px`, transform: 'none',
                });
            },
            previous() {
                if (this.busy || this.index === 0) return;
                this.index--;
                this.showStep();
            },
            next() {
                if (this.busy) return;
                if (this.index === this.steps.length - 1) return this.finish('complete');
                this.index++;
                this.showStep();
            },
            onKey(event) {
                if (!this.active) return;
                if (event.key === 'Escape') {
                    event.preventDefault(); event.stopImmediatePropagation();
                    this.finish('skip');
                } else if (['ArrowLeft', 'ArrowRight'].includes(event.key)) {
                    event.preventDefault(); event.stopImmediatePropagation();
                    const forward = document.documentElement.dir === 'rtl' ? 'ArrowLeft' : 'ArrowRight';
                    event.key === forward ? this.next() : this.previous();
                }
            },
            async finish(method) {
                if (this.busy) return;
                this.busy = true;
                this.failed = false;
                try {
                    await wire[method]();
                } catch {
                    this.failed = true;
                    this.busy = false;
                    this.$refs.heading.focus({ preventScroll: true });
                }
            },
            destroy() {
                this.active = false;
                cancelAnimationFrame(this.frame);
                this.observer?.disconnect();
                window.removeEventListener('resize', this.reposition);
                window.removeEventListener('scroll', this.reposition, true);
                const focus = this.previousFocus?.isConnected && this.previousFocus.getClientRects().length
                    ? this.previousFocus : document.querySelector('.fi-user-menu-trigger');
                focus?.focus({ preventScroll: true });
            },
        }));
    };
    if (window.Alpine) register();
    else document.addEventListener('alpine:init', register, { once: true });
})();
