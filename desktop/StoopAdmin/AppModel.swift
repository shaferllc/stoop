import AppKit
import Observation
import UserNotifications

enum Section: String, CaseIterable, Identifiable {
    case overview, activity, queue, appeals, messages, penalties, people, spam, bots, insights, maintenance, roles, audit
    var id: String { rawValue }

    var title: String {
        switch self {
        case .overview: "Overview"
        case .queue: "Queue"
        case .appeals: "Appeals"
        case .messages: "Messages"
        case .penalties: "Penalties"
        case .people: "People"
        case .maintenance: "Maintenance"
        case .audit: "Audit Log"
        case .insights: "Insights"
        case .activity: "Activity"
        case .spam: "Spam"
        case .bots: "House Bots"
        case .roles: "Roles"
        }
    }

    var icon: String {
        switch self {
        case .overview: "square.grid.2x2"
        case .queue: "tray.full"
        case .appeals: "arrow.uturn.backward.circle"
        case .messages: "bubble.left.and.exclamationmark.bubble.right"
        case .penalties: "hand.raised"
        case .people: "person.2"
        case .maintenance: "wrench.and.screwdriver"
        case .audit: "list.bullet.rectangle"
        case .insights: "chart.bar.xaxis"
        case .activity: "chart.bar.xaxis"
        case .spam: "exclamationmark.shield"
        case .bots: "bubble.left.and.text.bubble.right"
        case .roles: "person.badge.key"
        }
    }

    /// Which attention count shows as this section's badge.
    var attentionKey: String? {
        switch self {
        case .queue: "queue"
        case .appeals: "appeals"
        case .messages: "messages"
        case .people: "bans"
        default: nil
        }
    }
}

@MainActor @Observable
final class AppModel {
    var api: API?
    var me: Me?
    var attention: Attention?
    var section: Section? = .overview
    var toast: String?
    /// Bumped to make the visible screen reload.
    var reloadToken = 0

    private var timer: Timer?
    private var previous: Attention?

    var siteURL: String { UserDefaults.standard.string(forKey: "siteURL") ?? "http://stoop.test" }

    init() {
        if let token = Keychain.read("token"), let url = URL(string: siteURL) {
            api = API(baseURL: url, token: token)
            Task { await loadMe() }
        }
        UNUserNotificationCenter.current().requestAuthorization(options: [.alert, .sound, .badge]) { _, _ in }
    }

    func loadMe() async {
        guard let api else { return }
        do {
            me = try await api.get("me")
            startPolling()
        } catch let failure as API.Failure where failure.status == 401 || failure.status == 403 {
            disconnect()
        } catch {
            show(error.localizedDescription)
        }
    }

    /// Checks the token against the site before saving it.
    func connect(url: String, token: String) async throws {
        let trimmed = url.trimmingCharacters(in: .whitespaces).trimmingCharacters(in: CharacterSet(charactersIn: "/"))
        guard let base = URL(string: trimmed), ["http", "https"].contains(base.scheme) else {
            throw API.Failure(status: 422, message: "The address has to start with http:// or https://")
        }
        let candidate = API(baseURL: base, token: token.trimmingCharacters(in: .whitespacesAndNewlines))
        let me: Me = try await candidate.get("me")
        UserDefaults.standard.set(trimmed, forKey: "siteURL")
        Keychain.save(candidate.token, for: "token")
        api = candidate
        self.me = me
        previous = nil
        startPolling()
    }

    func disconnect() {
        Keychain.save(nil, for: "token")
        api = nil
        me = nil
        attention = nil
        timer?.invalidate()
        NSApp.dockTile.badgeLabel = nil
    }

    func startPolling() {
        timer?.invalidate()
        timer = Timer.scheduledTimer(withTimeInterval: 30, repeats: true) { _ in
            Task { @MainActor in await self.poll() }
        }
        Task { await poll() }
    }

    /// Updates the Dock badge and posts a notification when new work shows up.
    func poll() async {
        guard let api, let fresh: Attention = try? await api.get("attention") else { return }
        attention = fresh
        NSApp.dockTile.badgeLabel = fresh.total > 0 ? "\(fresh.total)" : nil
        if let previous {
            for item in fresh.items where item.count > previous.count(item.key) {
                let content = UNMutableNotificationContent()
                content.title = item.label
                content.body = "\(item.count) waiting on Stoop."
                content.userInfo = ["key": item.key]
                try? await UNUserNotificationCenter.current().add(UNNotificationRequest(identifier: UUID().uuidString, content: content, trigger: nil))
            }
        }
        previous = fresh
    }

    func open(_ key: String) {
        section = switch key {
        case "appeals": .appeals
        case "messages": .messages
        case "bans": .people
        case "claims": .overview
        default: .queue
        }
        NSApp.activate()
    }

    /// Runs an action, shows a toast, and reloads what's on screen.
    func act(_ message: String, _ work: @escaping (API) async throws -> Void) {
        guard let api else { return }
        Task {
            do {
                try await work(api)
                show(message)
                reloadToken += 1
                await poll()
            } catch {
                show(error.localizedDescription)
            }
        }
    }

    func show(_ message: String) {
        toast = message
        Task {
            try? await Task.sleep(for: .seconds(2.6))
            if toast == message { toast = nil }
        }
    }

    func openOnSite(_ url: String) {
        // Only links back to the connected site.
        guard url.hasPrefix(siteURL), let link = URL(string: url) else { return }
        NSWorkspace.shared.open(link)
    }
}
