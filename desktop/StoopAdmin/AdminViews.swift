import SwiftUI

struct PeopleView: View {
    @Environment(AppModel.self) private var model
    @State private var query = ""
    @State private var filter = "all"
    @State private var page: UsersPage?
    @State private var selected = Set<Int>()

    var body: some View {
        Group {
            if let page {
                if page.data.isEmpty {
                    ContentUnavailableView.search(text: query)
                } else {
                    Table(page.data, selection: $selected) {
                        TableColumn("Person") { u in
                            NavigationLink(value: u) {
                                HStack(spacing: 6) {
                                    Text("@\(u.username)").fontWeight(.semibold)
                                    Text(u.name ?? "").foregroundStyle(.secondary)
                                    if u.isStaff { Pill("STAFF") }
                                    if u.isBot { Pill("BOT") }
                                }
                            }
                            .buttonStyle(.link)
                        }
                        TableColumn("Email") { u in
                            HStack { Text(u.email ?? ""); if u.verified == false { Pill("unverified", red: true) } }
                        }
                        TableColumn("Posts") { Text("\($0.posts ?? 0)") }.width(50)
                        TableColumn("Joined") { Text(ago($0.createdAt)) }.width(90)
                        TableColumn("") { u in if u.penalized == true { Pill("penalized", red: true) } }.width(80)
                    }
                }
            } else {
                ProgressView().frame(maxWidth: .infinity, maxHeight: .infinity)
            }
        }
        .navigationTitle("People")
        .searchable(text: $query, placement: .toolbar, prompt: "@username, name or email")
        .toolbar {
            if !selected.isEmpty { BulkMenu(ids: selected) { selected = [] } }
            Picker("Show", selection: $filter) {
                Text("Everyone").tag("all")
                Text("4+ strikes: review for a ban").tag("ban_review")
                Text("In a timeout or banned").tag("penalized")
                Text("Staff").tag("staff")
                Text("Unverified").tag("unverified")
            }
        }
        .task(id: "\(query)|\(filter)|\(model.reloadToken)") {
            try? await Task.sleep(for: .milliseconds(250))
            guard !Task.isCancelled, let api = model.api else { return }
            let q = query.addingPercentEncoding(withAllowedCharacters: .alphanumerics) ?? ""
            page = try? await api.get("users?q=\(q)&filter=\(filter)")
        }
        .onAppear { if (model.attention?.count("bans") ?? 0) > 0 && page == nil { filter = "ban_review" } }
    }
}

struct PersonView: View {
    @Environment(AppModel.self) private var model
    let id: Int
    @State private var type = "warning"
    @State private var reason = ""
    @State private var note = ""
    @State private var confirming = false

    var body: some View {
        Loaded(path: "users/\(id)") { (u: PersonDetail) in
            let p = u.person
            ScrollView {
                VStack(alignment: .leading, spacing: 16) {
                    VStack(alignment: .leading, spacing: 4) {
                        Text("@\(p.username)").font(.system(.largeTitle, design: .serif).bold())
                        Text([p.name, p.email, "joined \(ago(p.createdAt))"].compactMap { $0 }.joined(separator: " · ")).foregroundStyle(.secondary)
                    }
                    HStack {
                        Pill("\(u.strikes) strikes in 90 days", red: u.strikes > 0)
                        if p.verified == true {
                            Pill("Verified")
                        } else {
                            Button("Verify Email") { model.act("Verified.") { try await $0.post("users/\(p.id)/verify") } }
                        }
                        Button("View Profile on Site") { model.openOnSite(p.url) }.buttonStyle(.link)
                    }
                    AccountCheckBox(id: p.id)

                    GroupBox("Issue a penalty") {
                        HStack {
                            Picker("Penalty", selection: $type) {
                                ForEach(model.me?.options.penalties.filter { $0.value != "none" } ?? [], id: \.value) { Text($0.label).tag($0.value) }
                            }
                            Picker("Rule", selection: $reason) {
                                ForEach(model.me?.options.ruleReasons ?? [], id: \.self) { Text($0).tag($0) }
                            }
                            Button("Issue…", role: .destructive) { confirming = true }
                        }
                        .padding(6)
                    }
                    .confirmationDialog("Give @\(p.username) this penalty for “\(reason)”? They’ll be told why.", isPresented: $confirming) {
                        Button("Issue Penalty", role: .destructive) {
                            model.act("Penalty issued.") { [type, reason] in try await $0.post("users/\(p.id)/penalize", ["type": type, "reason": reason]) }
                        }
                    }

                    Text("Penalties").font(.title2.bold())
                    if u.penalties.isEmpty {
                        Text("None.").foregroundStyle(.secondary)
                    } else {
                        ForEach(u.penalties) { pen in
                            HStack {
                                Text(pen.label).fontWeight(.semibold)
                                Text(pen.reason)
                                Text(ago(pen.createdAt)).foregroundStyle(.secondary)
                                Spacer()
                                if pen.liftedAt != nil {
                                    Pill("lifted")
                                } else {
                                    Button("Lift") { model.act("Lifted.") { try await $0.post("penalties/\(pen.id)/lift") } }
                                }
                            }
                            Divider()
                        }
                    }

                    Text("Staff notes").font(.title2.bold())
                    ForEach(u.notes, id: \.self) { n in
                        HStack(alignment: .firstTextBaseline) { Text(n.body); Text(ago(n.createdAt)).font(.caption).foregroundStyle(.secondary) }
                    }
                    HStack {
                        TextField("Private note for staff", text: $note, axis: .vertical)
                        Button("Add Note") {
                            let body = note
                            note = ""
                            model.act("Note added.") { try await $0.post("users/\(p.id)/notes", ["body": body]) }
                        }
                        .disabled(note.trimmingCharacters(in: .whitespaces).isEmpty)
                    }

                    Text("Recent dispatches").font(.title2.bold())
                    if u.dispatches.isEmpty { Text("None.").foregroundStyle(.secondary) }
                    ForEach(u.dispatches) { DispatchCard(dispatch: $0); Divider() }
                }
                .padding(24)
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            .navigationTitle("@\(p.username)")
        }
        .onAppear { if reason.isEmpty { reason = model.me?.options.ruleReasons.first ?? "" } }
    }
}

struct MaintenanceView: View {
    @Environment(AppModel.self) private var model
    @State private var running: String?

    var body: some View {
        Loaded(path: "health") { (health: Health) in
            ScrollView {
                VStack(alignment: .leading, spacing: 16) {
                    Text("\(health.environment) · Laravel \(health.laravel) · PHP \(health.php) · database \(bytes(health.databaseBytes))")
                        .foregroundStyle(.secondary)

                    GroupBox {
                        VStack(spacing: 0) {
                            ForEach(health.checks, id: \.self) { c in
                                HStack {
                                    Image(systemName: c.ok ? "checkmark.circle.fill" : "xmark.octagon.fill").foregroundStyle(c.ok ? .green : Color.flag)
                                    Text(c.name)
                                    Spacer()
                                    if !c.ok { Text(c.fix).font(.callout.monospaced()).foregroundStyle(.secondary).textSelection(.enabled) }
                                }
                                .padding(.vertical, 6)
                                if c != health.checks.last { Divider() }
                            }
                        }
                        .padding(.horizontal, 6)
                    }
                    if !health.pendingMigrations.isEmpty {
                        Text("Pending migrations: " + health.pendingMigrations.joined(separator: ", ")).foregroundStyle(Color.flag)
                    }

                    Text("Chores").font(.title2.bold())
                    HStack {
                        ForEach(health.tasks, id: \.self) { task in
                            Button(running == task.key ? "Running…" : task.label) { run(task.key) }
                                .disabled(running != nil)
                        }
                    }
                    Text("Scheduler last ran \(health.scheduleRanAt.map { ago($0) } ?? "never") · \(health.jobsWaiting) jobs waiting · \(health.jobsFailed) failed")
                        .font(.callout).foregroundStyle(.secondary)

                    Text("Backups").font(.title2.bold())
                    if health.backups.isEmpty {
                        Text("No backups yet. They’re saved in storage/app/backups on the server.").foregroundStyle(.secondary)
                    }
                    ForEach(health.backups, id: \.self) { b in
                        HStack { Text(b.name).monospaced(); Spacer(); Text(bytes(b.bytes)).foregroundStyle(.secondary) }
                    }

                    Text("Failed jobs").font(.title2.bold())
                    Loaded(path: "failed-jobs") { (jobs: [FailedJob]) in
                        if jobs.isEmpty { Text("No failed jobs.").foregroundStyle(.secondary) }
                        ForEach(jobs) { job in
                            HStack(alignment: .top) {
                                VStack(alignment: .leading) {
                                    Text(job.name).fontWeight(.semibold)
                                    Text(job.error).font(.caption.monospaced()).foregroundStyle(.secondary).lineLimit(2)
                                }
                                Spacer()
                                Text(ago(job.failedAt)).foregroundStyle(.secondary)
                                Button("Retry") { model.act("Queued again.") { try await $0.post("failed-jobs/\(job.uuid)/retry") } }
                                Button("Dismiss") { model.act("Dismissed.") { try await $0.delete("failed-jobs/\(job.uuid)") } }
                            }
                            Divider()
                        }
                    }

                    Text("Log").font(.title2.bold())
                    Loaded(path: "log") { (log: LogText) in
                        ScrollView {
                            Text(log.log.isEmpty ? "The log is empty." : log.log)
                                .font(.caption.monospaced())
                                .textSelection(.enabled)
                                .frame(maxWidth: .infinity, alignment: .leading)
                                .padding(12)
                        }
                        .defaultScrollAnchor(.bottom)
                        .frame(height: 360)
                        .background(Color.black.opacity(0.85), in: RoundedRectangle(cornerRadius: 10))
                        .foregroundStyle(Color(white: 0.85))
                    }
                }
                .padding(24)
                .frame(maxWidth: .infinity, alignment: .leading)
            }
        }
        .navigationTitle("Maintenance")
    }

    func run(_ key: String) {
        guard let api = model.api else { return }
        running = key
        Task {
            defer { running = nil }
            do {
                let result = try await api.post("maintenance/\(key)")
                model.show(result.message ?? "Done.")
                model.reloadToken += 1
            } catch {
                model.show(error.localizedDescription)
            }
        }
    }
}

struct AuditView: View {
    var body: some View {
        Loaded(path: "audit") { (rows: [AuditRow]) in
            Table(rows) {
                TableColumn("When") { Text(ago($0.createdAt)) }.width(90)
                TableColumn("Who") { Text($0.actor.map { "@\($0)" } ?? "—") }.width(120)
                TableColumn("Action", value: \.action)
                TableColumn("On") { r in Text(r.subjectType.map { "\($0) #\(r.subjectId ?? 0)" } ?? "") }.width(120)
                TableColumn("Details") { Text($0.details ?? "").font(.caption.monospaced()).foregroundStyle(.secondary) }
            }
        }
        .navigationTitle("Audit Log")
    }
}
