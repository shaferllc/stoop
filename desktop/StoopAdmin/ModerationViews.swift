import SwiftUI

struct OverviewView: View {
    @Environment(AppModel.self) private var model

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 20) {
                if let attention = model.attention {
                    Text(attention.total > 0 ? "\(attention.total) things need a moderator." : "Nothing waiting. Nice.")
                        .foregroundStyle(.secondary)
                    LazyVGrid(columns: [GridItem(.adaptive(minimum: 220), spacing: 12)], spacing: 12) {
                        ForEach(attention.items) { item in
                            Button { model.open(item.key) } label: {
                                Tile(value: item.count, label: item.label, hot: item.count > 0)
                            }
                            .buttonStyle(.plain)
                        }
                    }
                }

                Loaded(path: "stats") { (stats: Stats) in
                    VStack(alignment: .leading, spacing: 10) {
                        Text("Activity").font(.title2.bold())
                        LazyVGrid(columns: [GridItem(.adaptive(minimum: 180), spacing: 12)], spacing: 12) {
                            Tile(value: stats.accounts, label: "Accounts")
                            Tile(value: stats.newAccountsWeek, label: "New accounts, 7 days")
                            Tile(value: stats.postersDay, label: "People who posted, 24h")
                            Tile(value: stats.dispatchesDay, label: "Dispatches, 24h")
                            Tile(value: stats.weeklyPosters, label: "Weekly posters")
                            Tile(value: stats.scheduled, label: "Scheduled")
                            Tile(value: stats.removedWeek, label: "Removed, 7 days")
                            Tile(value: stats.penaltiesWeek, label: "Penalties, 7 days")
                        }
                    }
                }

                if model.allows(.spam) { SentinelPanel() }

                Loaded(path: "health") { (health: Health) in
                    let down = health.checks.filter { !$0.ok }
                    VStack(alignment: .leading, spacing: 10) {
                        Text("Site health").font(.title2.bold())
                        GroupBox {
                            HStack {
                                Image(systemName: down.isEmpty ? "checkmark.seal.fill" : "exclamationmark.triangle.fill")
                                    .foregroundStyle(down.isEmpty ? .green : Color.flag)
                                Text(down.isEmpty ? "Every integration is set up and the scheduler is running." : "Needs attention: " + down.map(\.name).joined(separator: ", "))
                                Spacer()
                                if !down.isEmpty { Button("Open Maintenance") { model.section = .maintenance } }
                            }
                            .padding(4)
                        }
                    }
                }
            }
            .padding(24)
            .frame(maxWidth: .infinity, alignment: .leading)
        }
        .navigationTitle("Overview")
    }
}

struct Tile: View {
    let value: Int
    let label: String
    var hot = false

    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(value.formatted()).font(.system(size: 28, weight: .bold, design: .serif)).foregroundStyle(hot ? Color.flag : .primary)
            Text(label).font(.callout).foregroundStyle(.secondary).multilineTextAlignment(.leading)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(14)
        .background(.background, in: RoundedRectangle(cornerRadius: 12))
        .overlay(RoundedRectangle(cornerRadius: 12).strokeBorder(hot ? Color.flag : Color.secondary.opacity(0.2)))
    }
}

struct QueueView: View {
    @Environment(AppModel.self) private var model
    @State private var selection: Int?
    @State private var removing: Dispatch?
    @State private var ai = QueueAI()
    @State private var accepting = false

    var body: some View {
        Loaded(path: "queue") { (items: [Dispatch]) in
            if items.isEmpty {
                ContentUnavailableView("The queue is empty", systemImage: "checkmark.circle", description: Text("Held, hidden, reported and flagged dispatches show up here."))
            } else {
                List(items, selection: $selection) { d in
                    VStack(alignment: .leading, spacing: 0) {
                        DispatchCard(dispatch: d, blur: ai.items[d.id]?.blur ?? !ai.loaded) {
                            Button("Approve") { approve(d) }.buttonStyle(.borderedProminent)
                            Button("Remove…", role: .destructive) { accepting = false; removing = d }
                        }
                        QueueAssistView(id: d.id, ai: ai) { accept(d) }.padding(.bottom, 8)
                    }
                    .tag(d.id)
                }
                // Plain letters only, so ⌘R still just refreshes.
                .onKeyPress(characters: CharacterSet(charactersIn: "aros"), phases: .down) { press in
                    guard press.modifiers.isEmpty else { return .ignored }
                    return act(items) { d in
                        switch press.characters {
                        case "a": approve(d)
                        case "r": accepting = false; removing = d
                        case "s": accept(d)
                        default: model.openOnSite(d.url)
                        }
                    }
                }
                .onAppear { selection = selection ?? items.first?.id }
            }
        }
        .task(id: model.reloadToken) { await ai.load(model.api) }
        .navigationTitle("Queue")
        .navigationSubtitle("↑↓ move · A approve · R remove · S accept suggestion · O open on the site")
        .sheet(item: $removing) { RemoveSheet(dispatch: $0, suggested: accepting ? ai.suggestions[$0.id] : nil) }
    }

    func approve(_ d: Dispatch) {
        model.act("Approved.") { try await $0.post("dispatches/\(d.id)/approve") }
    }

    /// Claude's suggestion: approve, or open the remove sheet filled in for a moderator to confirm.
    func accept(_ d: Dispatch) {
        guard let s = ai.suggestions[d.id] else { return }
        if s.action == "approve" {
            approve(d)
        } else {
            accepting = true
            removing = d
        }
    }

    func act(_ items: [Dispatch], _ fn: (Dispatch) -> Void) -> KeyPress.Result {
        guard let d = items.first(where: { $0.id == selection }) else { return .ignored }
        fn(d)
        return .handled
    }
}

struct RemoveSheet: View {
    @Environment(AppModel.self) private var model
    @Environment(\.dismiss) private var dismiss
    let dispatch: Dispatch
    var suggested: Suggestion?
    @State private var reason = ""
    @State private var note = ""
    @State private var penalty = "none"

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text("Remove @\(dispatch.user.username)’s dispatch").font(.title2.bold())
            Text(dispatch.body).foregroundStyle(.secondary).lineLimit(4)
            Text("The author is told the reason and can appeal once.").font(.callout).foregroundStyle(.secondary)
            Form {
                Picker("Rule broken", selection: $reason) {
                    ForEach(model.me?.options.ruleReasons ?? [], id: \.self) { Text($0).tag($0) }
                }
                TextField("Note for the author (optional)", text: $note)
                Picker("Penalty", selection: $penalty) {
                    ForEach(model.me?.options.penalties ?? [], id: \.value) { Text($0.label).tag($0.value) }
                }
            }
            .formStyle(.grouped)
            HStack {
                Spacer()
                Button("Cancel", role: .cancel) { dismiss() }.keyboardShortcut(.cancelAction)
                Button("Remove", role: .destructive) {
                    let body: [String: Any] = ["reason": reason, "note": note.isEmpty ? NSNull() : String(note.prefix(200)), "penalty": penalty]
                    model.act("Removed.") { [id = dispatch.id] in try await $0.post("dispatches/\(id)/remove", body) }
                    dismiss()
                }
                .keyboardShortcut(.defaultAction)
            }
        }
        .padding(24)
        .frame(width: 480)
        .onAppear {
            reason = suggested?.rule ?? model.me?.options.ruleReasons.first ?? ""
            penalty = suggested?.penalty ?? "none"
        }
    }
}

struct DispatchCard<Actions: View>: View {
    @Environment(AppModel.self) private var model
    let dispatch: Dispatch
    /// Possibly hateful or graphic: blurred until clicked, to spare moderators.
    var blur = false
    @ViewBuilder var actions: () -> Actions
    @State private var revealed = false

    var body: some View {
        let d = dispatch
        let hidden = blur && !revealed
        VStack(alignment: .leading, spacing: 8) {
            HStack(spacing: 6) {
                NavigationLink(value: d.user) { Text("@\(d.user.username)").fontWeight(.semibold) }.buttonStyle(.link)
                if let strikes = d.strikes, strikes > 0 { Pill("\(strikes) strikes", red: true) }
                Text(ago(d.createdAt)).foregroundStyle(.secondary)
                if let line = d.line, let info = model.me?.options.lines[line] {
                    Pill(info.name, dot: Color(hex: info.color))
                }
                if let stop = d.stopName { Pill(stop) }
                Pill(d.status, red: ["held", "hidden", "removed", "deleted"].contains(d.status))
                if d.flaggedAt != nil { Pill("pile-on", red: true) }
                Spacer()
                Button("Open on Site") { model.openOnSite(d.url) }.buttonStyle(.link)
            }
            .font(.callout)

            Group {
                Text(d.body.isEmpty ? "(no text)" : d.body).font(.body).textSelection(.enabled)

                if !d.images.isEmpty {
                    HStack {
                        ForEach(d.images, id: \.self) { image in
                            AsyncImage(url: URL(string: image.url)) { $0.resizable().scaledToFit() } placeholder: { Color.secondary.opacity(0.1) }
                                .frame(height: 90).clipShape(RoundedRectangle(cornerRadius: 8))
                                .accessibilityLabel(image.alt ?? "Photo")
                        }
                    }
                }
            }
            .blur(radius: hidden ? 9 : 0)
            .overlay {
                if hidden {
                    Button("Possibly hateful or graphic. Click to show.") { revealed = true }.buttonStyle(.bordered)
                }
            }
            if let reason = d.moderationReason { Text("Reason: \(reason)").font(.callout).foregroundStyle(.secondary) }
            if let scores = d.screening?.openai, !scores.isEmpty {
                HStack {
                    Text("OpenAI").font(.caption).foregroundStyle(.secondary)
                    ForEach(scores.filter { $0.value >= 0.05 }.sorted { $0.value > $1.value }.prefix(6), id: \.key) {
                        Pill("\($0.key) \(Int($0.value * 100))%", red: $0.value >= 0.4)
                    }
                }
            }
            if d.screening?.claude == "hold" { Text("Claude held this for review.").font(.callout).foregroundStyle(.secondary) }
            if !d.reports.isEmpty {
                VStack(alignment: .leading, spacing: 2) {
                    ForEach(d.reports, id: \.self) { r in
                        Label("\(r.reason)\(r.note.map { " — “\($0)”" } ?? "")\(r.by.map { " (@\($0))" } ?? "")", systemImage: "flag")
                            .font(.callout).foregroundStyle(.secondary)
                    }
                }
            }
            HStack { actions() }
        }
        .padding(.vertical, 8)
    }
}

extension DispatchCard where Actions == EmptyView {
    init(dispatch: Dispatch) { self.init(dispatch: dispatch) { EmptyView() } }
}

struct Pill: View {
    let text: String
    var red = false
    var dot: Color?

    init(_ text: String, red: Bool = false, dot: Color? = nil) {
        self.text = text
        self.red = red
        self.dot = dot
    }

    var body: some View {
        HStack(spacing: 4) {
            if let dot { Circle().fill(dot).frame(width: 8, height: 8) }
            Text(text)
        }
        .font(.caption.weight(.semibold))
        .padding(.horizontal, 8).padding(.vertical, 2)
        .foregroundStyle(red ? Color.flag : .primary)
        .background((red ? Color.flag : Color.secondary).opacity(0.14), in: Capsule())
    }
}

struct AppealsView: View {
    @Environment(AppModel.self) private var model

    var body: some View {
        Loaded(path: "appeals") { (items: [Appeal]) in
            if items.isEmpty {
                ContentUnavailableView("No open appeals", systemImage: "checkmark.circle", description: Text("Appeals on your own calls go to another moderator, so they don’t show here."))
            } else {
                List(items) { a in
                    VStack(alignment: .leading, spacing: 8) {
                        HStack {
                            NavigationLink(value: a.user) { Text("@\(a.user.username)").fontWeight(.semibold) }.buttonStyle(.link)
                            Text("appealed \(ago(a.createdAt))").foregroundStyle(.secondary)
                            if let p = a.penalty { Pill("\(p.label): \(p.reason)", red: true) }
                        }
                        Text(a.body).font(.title3).textSelection(.enabled)
                        AppealAssistView(id: a.id)
                        if let d = a.dispatch {
                            GroupBox { DispatchCard(dispatch: d).padding(.horizontal, 6) }
                        }
                        HStack {
                            Button("Grant and Reverse") {
                                model.act("Appeal granted.") { try await $0.post("appeals/\(a.id)/decide", ["grant": true]) }
                            }
                            .buttonStyle(.borderedProminent)
                            Button("Deny") {
                                model.act("Appeal denied.") { try await $0.post("appeals/\(a.id)/decide", ["grant": false]) }
                            }
                        }
                    }
                    .padding(.vertical, 8)
                }
            }
        }
        .navigationTitle("Appeals")
    }
}

struct MessageReportsView: View {
    @Environment(AppModel.self) private var model

    var body: some View {
        Loaded(path: "message-reports") { (items: [MessageReport]) in
            if items.isEmpty {
                ContentUnavailableView("No reported messages", systemImage: "checkmark.circle")
            } else {
                List(items) { r in
                    VStack(alignment: .leading, spacing: 8) {
                        HStack {
                            Text("@\(r.reporter.username) reported \(ago(r.createdAt))").foregroundStyle(.secondary)
                            Pill(r.reason, red: true)
                            if let note = r.note { Text("“\(note)”").italic() }
                        }
                        VStack(alignment: .leading, spacing: 4) {
                            ForEach(r.context) { line in
                                (Text(line.user.map { "@\($0.username)" } ?? "deleted").bold() + Text(": " + line.body))
                                    .padding(.horizontal, 6).padding(.vertical, 2)
                                    .background(line.reported ? Color.flag.opacity(0.12) : .clear, in: RoundedRectangle(cornerRadius: 6))
                            }
                        }
                        .padding(.leading, 10)
                        .overlay(alignment: .leading) { Rectangle().fill(.quaternary).frame(width: 3) }
                        HStack {
                            Button("No Action") { resolve(r, 0) }
                            Menu("Timeout the Sender") {
                                ForEach(model.me?.options.penalties.filter { Int($0.value) != nil } ?? [], id: \.value) { option in
                                    Button(option.label) { resolve(r, Int(option.value)!) }
                                }
                            }
                            .fixedSize()
                        }
                    }
                    .padding(.vertical, 8)
                }
            }
        }
        .navigationTitle("Reported Messages")
    }

    func resolve(_ r: MessageReport, _ hours: Int) {
        model.act(hours > 0 ? "Resolved with a timeout." : "Resolved.") { try await $0.post("message-reports/\(r.id)/resolve", ["timeout_hours": hours]) }
    }
}

struct PenaltiesView: View {
    @Environment(AppModel.self) private var model
    @State private var lifting: Penalty?

    var body: some View {
        Loaded(path: "penalties") { (items: [Penalty]) in
            if items.isEmpty {
                ContentUnavailableView("Nobody is in a timeout or banned", systemImage: "checkmark.circle")
            } else {
                Table(items) {
                    TableColumn("Person") { p in
                        if let user = p.user { NavigationLink(value: user) { Text("@\(user.username)") }.buttonStyle(.link) }
                    }
                    TableColumn("Penalty", value: \.label)
                    TableColumn("Reason", value: \.reason)
                    TableColumn("Issued") { Text(ago($0.createdAt)) }.width(90)
                    TableColumn("") { p in Button("Lift…") { lifting = p } }.width(60)
                }
            }
        }
        .navigationTitle("Active Timeouts and Bans")
        .confirmationDialog("Lift this penalty?", isPresented: Binding(get: { lifting != nil }, set: { if !$0 { lifting = nil } }), presenting: lifting) { p in
            Button("Lift \(p.type) for @\(p.user?.username ?? "")") {
                model.act("Lifted.") { try await $0.post("penalties/\(p.id)/lift") }
            }
        }
    }
}
