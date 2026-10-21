import SwiftUI
import UserNotifications

@main
struct StoopAdminApp: App {
    @NSApplicationDelegateAdaptor private var delegate: AppDelegate
    @State private var model = AppModel()

    var body: some Scene {
        WindowGroup("Stoop Admin", id: "main") {
            ContentView()
                .environment(model)
                .frame(minWidth: 960, minHeight: 620)
                .onAppear { delegate.model = model }
        }
        .commands {
            CommandMenu("Go") {
                ForEach(Array(Section.allCases.enumerated()), id: \.element) { index, section in
                    Button(section.title) { model.section = section }
                        .keyboardShortcut(KeyEquivalent(Character("\(index + 1)")), modifiers: .command)
                }
                Divider()
                Button("Refresh") { model.reloadToken += 1; Task { await model.poll() } }
                    .keyboardShortcut("r")
                Button("Open Stoop in Browser") { model.openOnSite(model.siteURL + "/") }
                    .disabled(model.me == nil)
                Divider()
                Button("Disconnect") { model.disconnect() }
                    .disabled(model.me == nil)
            }
        }

        // What needs a moderator, always one click away in the menu bar.
        MenuBarExtra {
            if let attention = model.attention {
                ForEach(attention.items) { item in
                    Button("\(item.label): \(item.count)") { model.open(item.key) }
                }
            } else {
                Text("Not connected")
            }
        } label: {
            let total = model.attention?.total ?? 0
            Label(total > 0 ? "\(total)" : "Stoop", systemImage: total > 0 ? "flag.fill" : "flag")
                .labelStyle(.titleAndIcon)
        }
    }
}

final class AppDelegate: NSObject, NSApplicationDelegate, UNUserNotificationCenterDelegate {
    var model: AppModel?

    func applicationDidFinishLaunching(_ notification: Notification) {
        UNUserNotificationCenter.current().delegate = self
    }

    func userNotificationCenter(_ center: UNUserNotificationCenter, willPresent notification: UNNotification) async -> UNNotificationPresentationOptions {
        [.banner, .sound]
    }

    func userNotificationCenter(_ center: UNUserNotificationCenter, didReceive response: UNNotificationResponse) async {
        let key = response.notification.request.content.userInfo["key"] as? String ?? "queue"
        await MainActor.run { model?.open(key) }
    }
}
