import Charts
import SwiftUI

// Bots, spam and operations: /api/admin routes from routes/api-admin-ops.php.

extension Section {
    /// The staff role a section needs. Nil means any staff role.
    var role: String? {
        switch self {
        case .queue, .appeals, .messages, .penalties, .people, .spam: "moderator"
        case .bots: "editor"
        case .maintenance, .roles: "admin"
        default: nil
        }
    }
}

extension AppModel {
    /// Hides sections the signed-in person's roles don't open. Older servers send no roles: show everything.
    func allows(_ section: Section) -> Bool {
        guard let role = section.role, let roles = me?.roles else { return true }
        return roles.contains(role)
    }
}

// MARK: Shapes

struct SpamCluster: Decodable, Identifiable {
    struct Member: Decodable, Hashable, Identifiable { let id: Int; let username: String; let name: String?; let createdAt: String? }
    struct Evidence: Decodable, Hashable { let userId: Int; let username: String?; let dispatchId: Int?; let text: String; let at: String? }
    let id: Int
    let kind: String
    let kindLabel: String
    let summary: String
    let status: String
    let members: [Member]
    let evidence: [Evidence]
    let updatedAt: String?
}

struct Risk: Decodable {
    let score: Int
    let reasons: [String]
    let held: Bool
    let clusters: [SpamCluster]
    let claude: Bool
}

struct AccountVerdict: Decodable {
    let verdict: String
    let label: String
    let confidence: Int
    let reasons: [String]
    let source: String
}

struct BotAnswer: Decodable, Identifiable {
    struct Question: Decodable { let id: Int; let body: String; let username: String? }
    let id: Int
    let bot: String
    let body: String
    let url: String
    let createdAt: String?
    let wrong: Bool
    let question: Question?
}

struct KnowledgeEntry: Decodable, Identifiable, Hashable {
    let id: Int
    let section: String
    let body: String
}

struct ActivityData: Decodable {
    struct Item: Decodable, Identifiable {
        let kind: String
        let at: String?
        let text: String
        let username: String?
        let url: String
        var id: String { "\(kind)|\(at ?? "")|\(url)" }
    }
    struct Day: Decodable, Identifiable {
        let day: String
        let posts: Int
        let removals: Int
        let signups: Int
        var id: String { day }
        var date: Date { dayFormat.date(from: day) ?? .now }
    }
    let feed: [Item]
    let days: [Day]
}

struct StaffRoles: Decodable {
    struct Option: Decodable, Hashable { let value: String; let label: String }
    struct Member: Decodable, Identifiable { let id: Int; let username: String; let name: String?; let roles: [String] }
    let options: [Option]
    let staff: [Member]
}

private let dayFormat: DateFormatter = {
    let f = DateFormatter()
    f.dateFormat = "yyyy-MM-dd"
    f.timeZone = TimeZone(identifier: "UTC")
    return f
}()

// MARK: Spam

struct SpamView: View {
    @Environment(AppModel.self) private var model
    @State private var status = "open"
    @State private var hours = "24"
    @State private var acting: SpamCluster?

    var body: some View {
        Loaded(path: "spam-clusters?status=\(status)") { (clusters: [SpamCluster]) in
            if clusters.isEmpty {
                ContentUnavailableView("No clusters", systemImage: "checkmark.shield", description: Text("Detection runs every 10 minutes."))
            } else {
                List(clusters) { c in
                    VStack(alignment: .leading, spacing: 6) {
                        HStack {
                            Pill(c.kindLabel.uppercased())
                            Text(c.summary).fontWeight(.semibold)
                            Text(ago(c.updatedAt)).foregroundStyle(.secondary)
                            Spacer()
                            if c.status == "open" {
                                Button("Hide Posts and Time Out…", role: .destructive) { acting = c }
                                Button("Not Spam") { model.act("Dismissed.") { try await $0.post("spam-clusters/\(c.id)/dismiss") } }
                            } else {
                                Pill(c.status == "actioned" ? "handled" : "dismissed")
                            }
                        }
                        HStack {
                            ForEach(c.members) { m in
                                NavigationLink(value: Person(id: m.id, username: m.username, name: m.name, url: "", isStaff: false, isBot: false)) {
                                    Text("@\(m.username)")
                                }
                                .buttonStyle(.link)
                            }
                        }
                        DisclosureGroup("Evidence (\(c.evidence.count))") {
                            ForEach(c.evidence, id: \.self) { e in
                                Text("@\(e.username ?? "?")  ").bold() + Text(e.text)
                            }
                        }
                        .foregroundStyle(.secondary)
                    }
                    .padding(.vertical, 4)
                }
            }
        }
        .navigationTitle("Spam")
        .toolbar {
            Picker("Show", selection: $status) {
                Text("Open").tag("open"); Text("Handled").tag("actioned"); Text("Dismissed").tag("dismissed"); Text("All").tag("all")
            }
            Picker("Timeout", selection: $hours) {
                ForEach(model.me?.options.penalties.filter { Int($0.value) != nil } ?? [], id: \.value) { Text($0.label).tag($0.value) }
            }
            Button("Run Detection") { model.act("Detection finished.") { try await $0.post("spam-clusters/detect") } }
        }
        .confirmationDialog("Hide every post from these \(acting?.members.count ?? 0) accounts and put them in a timeout? Bans stay one person at a time.", isPresented: Binding(get: { acting != nil }, set: { if !$0 { acting = nil } })) {
            Button("Hide and Time Out", role: .destructive) {
                if let id = acting?.id {
                    model.act("Done.") { [hours] in try await $0.post("spam-clusters/\(id)/act", ["hours": Int(hours) ?? 24]) }
                }
            }
        }
    }
}

/// Sign-up risk, spam clusters and the "is this a bot?" check, on a person's page.
struct AccountCheckBox: View {
    @Environment(AppModel.self) private var model
    let id: Int
    @State private var verdict: AccountVerdict?
    @State private var checking = false

    var body: some View {
        GroupBox("Bot and spam check") {
            VStack(alignment: .leading, spacing: 6) {
                Loaded(path: "users/\(id)/risk") { (risk: Risk) in
                    VStack(alignment: .leading, spacing: 4) {
                        HStack {
                            Pill("Sign-up risk \(risk.score)/100", red: risk.score >= 50)
                            if risk.held { Pill("first posts held", red: true) }
                            Text(risk.reasons.joined(separator: " · ")).foregroundStyle(.secondary)
                        }
                        ForEach(risk.clusters) { Text("In a spam cluster: \($0.summary)").foregroundStyle(Color.flag) }
                        HStack {
                            Button(checking ? "Checking…" : "Is This a Bot or a Spam Ring?") { check() }.disabled(checking)
                            Text(risk.claude ? "Asks Claude about recent activity." : "Needs ANTHROPIC_API_KEY for Claude; uses the built-in signals for now.")
                                .font(.caption).foregroundStyle(.secondary)
                        }
                    }
                }
                .frame(minHeight: 50)
                if let verdict {
                    VStack(alignment: .leading, spacing: 2) {
                        Text(verdict.label).bold() + Text("  \(verdict.confidence)% sure · \(verdict.source == "claude" ? "Claude" : "built-in signals")").foregroundStyle(.secondary)
                        ForEach(verdict.reasons, id: \.self) { Text("• \($0)") }
                        Text("A suggestion only. Nothing happens to the account unless you act.").font(.caption).foregroundStyle(.secondary)
                    }
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            .padding(6)
        }
    }

    func check() {
        guard let api = model.api else { return }
        checking = true
        Task {
            defer { checking = false }
            do { verdict = try await api.send("POST", "users/\(id)/check", body: [:]) } catch { model.show(error.localizedDescription) }
        }
    }
}

/// Verify, time out or add a note to everyone selected in People.
struct BulkMenu: View {
    @Environment(AppModel.self) private var model
    let ids: Set<Int>
    let done: () -> Void
    @State private var noting = false
    @State private var note = ""

    var body: some View {
        Menu("\(ids.count) Selected") {
            Button("Verify Email") { run(["action": "verify"], "Verified.") }
            Menu("Timeout") {
                ForEach(model.me?.options.penalties.filter { Int($0.value) != nil } ?? [], id: \.value) { length in
                    Menu(length.label) {
                        ForEach(model.me?.options.ruleReasons ?? [], id: \.self) { reason in
                            Button(reason) { run(["action": "timeout", "hours": Int(length.value) ?? 24, "reason": reason], "Timed out.") }
                        }
                    }
                }
            }
            Button("Add Note…") { noting = true }
        }
        .sheet(isPresented: $noting) {
            VStack(alignment: .leading) {
                Text("Note on \(ids.count) people").font(.headline)
                TextField("Private note for staff", text: $note, axis: .vertical).lineLimit(3...6)
                HStack {
                    Spacer()
                    Button("Cancel") { noting = false }
                    Button("Add Note") { run(["action": "note", "body": note], "Notes added."); noting = false; note = "" }
                        .keyboardShortcut(.defaultAction)
                        .disabled(note.trimmingCharacters(in: .whitespaces).isEmpty)
                }
            }
            .padding(20)
            .frame(width: 420)
        }
    }

    func run(_ body: [String: Any], _ message: String) {
        var body = body
        body["ids"] = Array(ids)
        model.act(message) { [body] in try await $0.post("users/bulk", body) }
        done()
    }
}

// MARK: House bots

struct BotsView: View {
    @Environment(AppModel.self) private var model
    @State private var correcting: BotAnswer?
    @State private var correction = ""
    @State private var editing: KnowledgeEntry?
    @State private var section = ""
    @State private var entry = ""

    var body: some View {
        HSplitView {
            Loaded(path: "bots/answers") { (answers: [BotAnswer]) in
                List(answers) { a in
                    VStack(alignment: .leading, spacing: 4) {
                        if let q = a.question { Text("@\(q.username ?? "?")  ").bold() + Text(q.body).foregroundStyle(.secondary) }
                        Text("@\(a.bot)  ").bold() + Text(a.body)
                        HStack {
                            Text(ago(a.createdAt)).foregroundStyle(.secondary)
                            if a.wrong { Pill("wrong", red: true) } else {
                                Button("Mark Wrong") { model.act("Marked wrong.") { try await $0.post("bots/answers/\(a.id)/wrong") } }
                            }
                            Button("Post a Correction…") { correcting = a }
                            Button("Open") { model.openOnSite(a.url) }.buttonStyle(.link)
                        }
                        .font(.callout)
                    }
                    .padding(.vertical, 4)
                }
            }
            .frame(minWidth: 380)

            VStack(alignment: .leading) {
                Text("@stoop’s knowledge base").font(.title3.bold())
                HStack {
                    TextField("Section", text: $section).frame(width: 120)
                    TextField("One fact @stoop can answer from", text: $entry)
                    Button(editing == nil ? "Add" : "Save") {
                        var body: [String: Any] = ["section": section, "body": entry]
                        if let id = editing?.id { body["id"] = id }
                        model.act("Saved.") { [body] in try await $0.post("bots/knowledge", body) }
                        editing = nil; section = ""; entry = ""
                    }
                    .disabled(section.isEmpty || entry.isEmpty)
                }
                Loaded(path: "bots/knowledge") { (entries: [KnowledgeEntry]) in
                    List(entries) { e in
                        HStack(alignment: .top) {
                            VStack(alignment: .leading) { Text(e.section).font(.caption).foregroundStyle(.secondary); Text(e.body) }
                            Spacer()
                            Button("Edit") { editing = e; section = e.section; entry = e.body }
                            Button("Delete") { model.act("Deleted.") { try await $0.delete("bots/knowledge/\(e.id)") } }
                        }
                    }
                }
            }
            .padding(12)
            .frame(minWidth: 380)
        }
        .navigationTitle("House Bots")
        .sheet(item: $correcting) { a in
            VStack(alignment: .leading) {
                Text("Reply to @\(a.bot)’s answer as @\(a.bot)").font(.headline)
                Text(a.body).foregroundStyle(.secondary)
                TextField("Correction: …", text: $correction, axis: .vertical).lineLimit(3...6)
                HStack {
                    Spacer()
                    Button("Cancel") { correcting = nil }
                    Button("Post") {
                        let body = correction
                        model.act("Correction posted.") { try await $0.post("bots/answers/\(a.id)/correct", ["body": body]) }
                        correcting = nil; correction = ""
                    }
                    .keyboardShortcut(.defaultAction)
                    .disabled(correction.count < 3)
                }
            }
            .padding(20)
            .frame(width: 460)
        }
    }
}

// MARK: Activity

struct ActivityView: View {
    @Environment(AppModel.self) private var model

    var body: some View {
        Loaded(path: "activity") { (data: ActivityData) in
            ScrollView {
                VStack(alignment: .leading, spacing: 16) {
                    HStack(spacing: 16) {
                        chart("Posts a day", data.days, \.posts, .primary)
                        chart("Removals a day", data.days, \.removals, Color.flag)
                        chart("Sign-ups a day", data.days, \.signups, .secondary)
                    }
                    Text("Live").font(.title2.bold())
                    ForEach(data.feed) { item in
                        HStack(alignment: .firstTextBaseline) {
                            Pill(item.kind.uppercased(), red: item.kind == "removal" || item.kind == "report").frame(width: 80, alignment: .leading)
                            if let u = item.username { Text("@\(u)").fontWeight(.semibold) }
                            Button(item.text) { model.openOnSite(item.url) }.buttonStyle(.link).lineLimit(1)
                            Spacer()
                            Text(ago(item.at)).foregroundStyle(.secondary)
                        }
                        Divider()
                    }
                }
                .padding(24)
            }
        }
        .navigationTitle("Activity")
        .task {
            // Live: refresh every 15 seconds while this screen is open.
            while !Task.isCancelled {
                try? await Task.sleep(for: .seconds(15))
                if !Task.isCancelled { model.reloadToken += 1 }
            }
        }
    }

    func chart(_ title: String, _ days: [ActivityData.Day], _ value: KeyPath<ActivityData.Day, Int>, _ color: Color) -> some View {
        GroupBox {
            Chart(days) { BarMark(x: .value("Day", $0.date, unit: .day), y: .value(title, $0[keyPath: value])).foregroundStyle(color) }
                .frame(height: 140)
        } label: {
            Text(title) + Text("  \(days.reduce(0) { $0 + $1[keyPath: value] }) in 30 days").foregroundStyle(.secondary)
        }
    }
}

// MARK: Roles

struct RolesView: View {
    @Environment(AppModel.self) private var model
    @State private var username = ""
    @State private var picked = Set<String>()

    var body: some View {
        Loaded(path: "roles") { (data: StaffRoles) in
            Form {
                SwiftUI.Section("Set someone’s roles") {
                    TextField("Username", text: $username)
                    ForEach(data.options, id: \.self) { option in
                        Toggle(option.label, isOn: Binding(get: { picked.contains(option.value) }, set: { on in if on { picked.insert(option.value) } else { picked.remove(option.value) } }))
                    }
                    Button("Save Roles") {
                        let body: [String: Any] = ["username": username.trimmingCharacters(in: CharacterSet(charactersIn: "@ ")), "roles": Array(picked)]
                        model.act("Roles saved.") { try await $0.post("roles", body) }
                    }
                    .disabled(username.isEmpty)
                    Text("No roles at all removes staff access.").font(.caption).foregroundStyle(.secondary)
                }
                SwiftUI.Section("Staff") {
                    ForEach(data.staff) { s in
                        HStack {
                            Text("@\(s.username)").fontWeight(.semibold)
                            Text(s.name ?? "").foregroundStyle(.secondary)
                            Spacer()
                            ForEach(s.roles, id: \.self) { Pill($0.uppercased()) }
                            Button("Change") { username = s.username; picked = Set(s.roles) }
                        }
                    }
                }
            }
            .formStyle(.grouped)
        }
        .navigationTitle("Roles")
    }
}
