// Kohevo Studio builder — advisory edit-session coordination.
//
// Uses the server's short-lived `studiobuilder_locks` row (TTL, DB clock) to
// warn when someone else has the page open. It never blocks editing and is
// never a substitute for revision concurrency: every write still carries
// `expected_revision_id`. The lock is refreshed while the builder is open and
// released when the tab goes away; if the tab dies it simply expires.

export class EditLock {
  constructor({ transport, pageId, onChange, intervalMs = 45000, setIntervalImpl, clearIntervalImpl }) {
    this.transport = transport;
    this.pageId = pageId;
    this.onChange = onChange || (() => {});
    this.intervalMs = intervalMs;
    this.setIntervalImpl = setIntervalImpl || ((fn, ms) => setInterval(fn, ms));
    this.clearIntervalImpl = clearIntervalImpl || ((h) => clearInterval(h));
    this.token = null;
    this.handle = null;
    this.state = { held: false, otherEditor: false };
  }

  apply(res) {
    if (!res || !res.ok) return; // advisory only: a failed heartbeat changes nothing
    const lock = res.data.lock || {};
    this.token = lock.held ? lock.lock_token : null;
    this.state = { held: !!lock.held, otherEditor: !!lock.other_editor };
    this.onChange(this.state);
  }

  async start() {
    this.apply(await this.transport.lockAcquire(this.pageId));
    this.handle = this.setIntervalImpl(() => this.beat(), this.intervalMs);
  }

  async beat() {
    const res = this.token
      ? await this.transport.lockRefresh(this.pageId, this.token)
      : await this.transport.lockAcquire(this.pageId);
    this.apply(res);
  }

  stop() {
    if (this.handle !== null) {
      this.clearIntervalImpl(this.handle);
      this.handle = null;
    }
    if (this.token) {
      const token = this.token;
      this.token = null;
      return this.transport.lockRelease(this.pageId, token);
    }
    return Promise.resolve(null);
  }
}
