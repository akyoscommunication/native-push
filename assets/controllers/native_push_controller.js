import { Controller } from '@hotwired/stimulus';

/*
 * Registers the device token with the server.
 * <div data-controller="bridge--notification-token native-push"
 *      data-action="bridge--notification-token:retrieved->native-push#register">
 */
export default class extends Controller {
    static values = { url: { type: String, default: '/native-push/devices' } };
    static targets = ['status'];

    async register({ detail: { token } }) {
        let body = {};
        let ok = false;
        try {
            const response = await fetch(this.urlValue, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ token }),
            });
            ok = response.ok;
            body = await response.json().catch(() => ({}));
        } catch (error) {
            body = { error: error.message };
        }

        this.dispatch(ok ? 'registered' : 'error', { detail: body });
        if (this.hasStatusTarget) {
            this.statusTarget.textContent = ok
                ? `Appareil enregistré (${body.platform}, ${body.subject}).`
                : `Enregistrement impossible : ${body.error ?? 'erreur inconnue'}`;
        }
    }
}
