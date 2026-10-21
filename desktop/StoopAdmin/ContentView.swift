import SwiftUI

struct ContentView: View {
    @Environment(AppModel.self) private var model

    var body: some View {
        @Bindable var model = model
        Group {
            if model.me == nil {
                ConnectView()
            } else {
                NavigationSplitView {
                    List(Section.allCases, selection: $model.section) { section in
                        Label(section.title, systemImage: section.icon)
                            .badge(section.attentionKey.map { model.attention?.count($0) ?? 0 } ?? 0)
                            .tag(section)
                    }
                    .navigationSplitViewColumnWidth(min: 190, ideal: 210)
                    .safeAreaInset(edge: .bottom) {
                        if let me = model.me {
                            VStack(alignment: .leading, spacing: 2) {
                                Text("@\(me.user.username)").font(.callout.weight(.semibold))
                                Text(me.site.url.replacingOccurrences(of: #"^https?://"#, with: "", options: .regularExpression))
                                    .font(.caption).foregroundStyle(.secondary)
                            }
                            .frame(maxWidth: .infinity, alignment: .leading)
                            .padding(12)
                        }
                    }
                } detail: {
                    NavigationStack {
                        Group {
                        switch model.section ?? .overview {
                        case .overview: OverviewView()
                        case .queue: QueueView()
                        case .appeals: AppealsView()
                        case .messages: MessageReportsView()
                        case .penalties: PenaltiesView()
                        case .people: PeopleView()
                        case .maintenance: MaintenanceView()
                        case .audit: AuditView()
                        case .radar: RadarView()
                        case .tips: TipsView()
                        }
                        }
                        .navigationDestination(for: Person.self) { PersonView(id: $0.id) }
                    }
                    .id(model.section)
                }
            }
        }
        .overlay(alignment: .bottom) {
            if let toast = model.toast {
                Text(toast)
                    .padding(.horizontal, 16).padding(.vertical, 8)
                    .background(.regularMaterial, in: Capsule())
                    .padding(.bottom, 24)
                    .transition(.move(edge: .bottom).combined(with: .opacity))
            }
        }
        .animation(.snappy, value: model.toast)
    }
}

/// Loads one API path, shows progress or an error, and reloads when an action changes data.
struct Loaded<T: Decodable, Content: View>: View {
    @Environment(AppModel.self) private var model
    let path: String
    @ViewBuilder let content: (T) -> Content
    @State private var value: T?
    @State private var error: String?

    var body: some View {
        Group {
            if let value {
                content(value)
            } else if let error {
                ContentUnavailableView {
                    Label("Couldn’t load this", systemImage: "exclamationmark.triangle")
                } description: {
                    Text(error)
                } actions: {
                    Button("Try Again") { Task { await load() } }
                }
            } else {
                ProgressView().frame(maxWidth: .infinity, maxHeight: .infinity)
            }
        }
        .task(id: "\(path)#\(model.reloadToken)") { await load() }
    }

    func load() async {
        guard let api = model.api else { return }
        do {
            value = try await api.get(path)
            error = nil
        } catch is CancellationError {
        } catch {
            if value == nil { self.error = error.localizedDescription }
        }
    }
}

struct ConnectView: View {
    @Environment(AppModel.self) private var model
    @State private var url = ""
    @State private var token = ""
    @State private var error: String?
    @State private var busy = false

    var body: some View {
        VStack(alignment: .leading, spacing: 14) {
            HStack(spacing: 10) {
                Roundel(colors: ["#bf0d3e", "#ed8b00", "#009cde", "#00b140", "#e0b400", "#919d9d"]).frame(width: 28, height: 28)
                Text("Connect to Stoop").font(.system(.largeTitle, design: .serif).bold())
            }
            Text("On the site, open Settings → Terminal & API, tick “For the Stoop Admin desktop app”, create a token and paste it here. Only staff can make one.")
                .foregroundStyle(.secondary)
                .fixedSize(horizontal: false, vertical: true)
            Form {
                TextField("Site address", text: $url)
                SecureField("Admin token", text: $token)
            }
            .formStyle(.grouped)
            if let error {
                Text(error).foregroundStyle(Color.flag)
            }
            HStack {
                Spacer()
                Button(busy ? "Connecting…" : "Connect") {
                    busy = true
                    Task {
                        defer { busy = false }
                        do { try await model.connect(url: url, token: token) } catch { self.error = error.localizedDescription }
                    }
                }
                .keyboardShortcut(.defaultAction)
                .disabled(busy || token.isEmpty)
            }
        }
        .padding(32)
        .frame(maxWidth: 520)
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .onAppear { url = model.siteURL }
    }
}

/// The Stoop mark: a Metro roundel split between line colors.
struct Roundel: View {
    let colors: [String]

    var body: some View {
        Canvas { context, size in
            let rect = CGRect(origin: .zero, size: size)
            let center = CGPoint(x: rect.midX, y: rect.midY)
            let step = 360.0 / Double(max(colors.count, 1))
            for (i, hex) in colors.enumerated() {
                var path = Path()
                path.move(to: center)
                path.addArc(center: center, radius: size.width / 2, startAngle: .degrees(-90 + step * Double(i)), endAngle: .degrees(-90 + step * Double(i + 1)), clockwise: false)
                context.fill(path, with: .color(Color(hex: hex)))
            }
        }
        .accessibilityHidden(true)
    }
}
