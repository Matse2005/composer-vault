/*
 * Alpine components of the admin interface.
 *
 * The interface runs under a Content Security Policy without 'unsafe-eval', so Livewire serves its CSP-safe Alpine
 * build. That build cannot evaluate methods, arrow functions or optional chaining written inline in x-data, so every
 * component that needs them is registered here with Alpine.data() and referenced by name in the views.
 */

const register = () => {
    const Alpine = window.Alpine;

    Alpine.data('passkeyRegistration', () => ({
        supported: false,
        showForm: false,
        name: '',
        loading: false,
        error: null,

        get cannotRegister() {
            return this.loading || !this.name.trim();
        },

        updateSupport() {
            this.supported = Boolean(window.Passkeys && window.Passkeys.isSupported());
        },

        getDefaultPasskeyName() {
            const ua = navigator.userAgent;

            const browser = [
                { pattern: /Edg|Edge/, name: 'Edge' },
                { pattern: /OPR|Opera|OPiOS/, name: 'Opera' },
                { pattern: /Firefox|FxiOS/, name: 'Firefox' },
                { pattern: /Chrome|CriOS/, name: 'Chrome' },
                { pattern: /Safari/, name: 'Safari' },
            ].find(({ pattern }) => pattern.test(ua));

            const os = [
                { pattern: /iPhone/, name: 'iPhone' },
                { pattern: /iPad|Macintosh(?=.*Mobile)/, name: 'iPad' },
                { pattern: /Android/, name: 'Android' },
                { pattern: /Mac/, name: 'Mac' },
                { pattern: /Windows/, name: 'Windows' },
            ].find(({ pattern }) => pattern.test(ua));

            return [browser && browser.name, os && os.name].filter(Boolean).join(' on ') || '';
        },

        init() {
            this.name = this.getDefaultPasskeyName();
            this.updateSupport();

            window.addEventListener('passkeys:ready', () => this.updateSupport(), { once: true });
        },

        focusName() {
            this.$nextTick(() => this.$refs.passkeyNameInput && this.$refs.passkeyNameInput.focus());
        },

        async register() {
            if (!this.name.trim()) return;

            this.loading = true;
            this.error = null;

            try {
                await window.Passkeys.register({ name: this.name });
                this.name = '';
                this.showForm = false;
                await this.$wire.loadPasskeys();
            } catch (e) {
                if (!e.constructor || e.constructor.name !== 'UserCancelledError') {
                    this.error = e.message;
                }
            } finally {
                this.loading = false;
            }
        },

        cancel() {
            this.showForm = false;
            this.name = '';
            this.error = null;
        },
    }));

    Alpine.data('passkeyVerify', () => ({
        supported: false,
        loading: false,
        error: null,

        updateSupport() {
            this.supported = Boolean(window.Passkeys && window.Passkeys.isSupported());
        },

        init() {
            this.updateSupport();

            window.addEventListener('passkeys:ready', () => this.updateSupport(), { once: true });
        },

        async verify() {
            this.loading = true;
            this.error = null;

            try {
                const response = await window.Passkeys.verify({
                    routes: {
                        options: this.$el.dataset.optionsUrl,
                        submit: this.$el.dataset.submitUrl,
                    },
                });

                window.Livewire.navigate(response.redirect || '/dashboard');
            } catch (e) {
                if (!e.constructor || e.constructor.name !== 'UserCancelledError') {
                    this.error = e.message;
                }
            } finally {
                this.loading = false;
            }
        },
    }));

    Alpine.data('twoFactorChallenge', () => ({
        showRecoveryInput: false,
        code: '',
        recovery_code: '',

        focusOtp() {
            this.$nextTick(() => {
                const input = this.$refs.otp && this.$refs.otp.querySelector('input');

                if (input) input.focus();
            });
        },

        init() {
            this.showRecoveryInput = this.$el.dataset.recoveryOpen === 'true';

            if (!this.showRecoveryInput) {
                this.focusOtp();
            }
        },

        toggleInput() {
            this.showRecoveryInput = !this.showRecoveryInput;

            this.code = '';
            this.recovery_code = '';

            this.$nextTick(() => {
                if (this.showRecoveryInput) {
                    if (this.$refs.recovery_code) this.$refs.recovery_code.focus();
                } else {
                    this.focusOtp();
                }
            });
        },
    }));

    Alpine.data('focusFirstInput', () => ({
        init() {
            this.$nextTick(() => {
                const input = this.$el.querySelector('input');

                if (input) input.focus();
            });
        },
    }));

    Alpine.data('copyToClipboard', () => ({
        copied: false,

        async copy() {
            try {
                await navigator.clipboard.writeText(this.$el.dataset.text);
                this.copied = true;
                setTimeout(() => (this.copied = false), 1500);
            } catch (e) {
                console.warn('Could not copy to clipboard');
            }
        },
    }));
};

if (window.Alpine) {
    register();
} else {
    document.addEventListener('alpine:init', register);
}
