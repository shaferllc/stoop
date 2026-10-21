import SwiftUI

// Newsroom tools: story radar and the tip inbox (/api/admin/radar, /api/admin/tips).

struct RadarReport: Decodable {
    struct Trend: Decodable, Identifiable {
        let key: String
        let label: String
        let current: Int
        var average: Double?
        var change: Int?
        var posts: Int?
        var replies: Int?
        var postIds: [Int]?
        let url: String
        var id: String { key }
    }

    let lines: [Trend]
    let stations: [Trend]
    let keywords: [Trend]
    let clusters: [Trend]
    let generatedAt: String
    let ideasEnabled: Bool
}

struct StoryIdea: Decodable { let idea: String? }

struct TipItem: Decodable, Identifiable {
    struct About: Decodable { let id: Int; let url: String; let body: String }

    let id: Int
    let body: String
    let link: String?
    let status: String
    let contactOk: Bool
    let photos: [String]
    let flagged: [String]
    let createdAt: String
    let user: Person
    let assignee: Person?
    let dispatch: About?
}

struct Editor: Decodable, Identifiable, Hashable { let id: Int; let username: String }

struct RadarView: View {
    @Environment(AppModel.self) private var model
    @State private var ideas: [String: String] = [:]
    @State private var asking: String?

    var body: some View {
        Loaded(path: "radar") { (r: RadarReport) in
            List {
                if !r.ideasEnabled {
                    Text("Story ideas: Needs ANTHROPIC_API_KEY").foregroundStyle(.secondary)
                }
                group("Topics", r.lines, ideas: r.ideasEnabled)
                group("Stations", r.stations, ideas: r.ideasEnabled)
                group("Rising words", r.keywords, ideas: r.ideasEnabled)
                group("Ask DC clusters", r.clusters, ideas: r.ideasEnabled)
            }
            .navigationSubtitle("Last 7 days vs. the 4 weeks before · updated \(ago(r.generatedAt))")
        }
        .navigationTitle("Radar")
        .toolbar {
            Button("Refresh", systemImage: "arrow.clockwise") {
                model.act("Refreshed.") { _ = try await $0.get("radar?fresh=1") as RadarReport }
            }
        }
    }

    @ViewBuilder
    func group(_ title: String, _ trends: [RadarReport.Trend], ideas enabled: Bool) -> some View {
        SwiftUI.Section(title) {
            if trends.isEmpty {
                Text("Nothing unusual this week.").foregroundStyle(.secondary)
            }
            ForEach(trends) { t in
                VStack(alignment: .leading, spacing: 4) {
                    HStack {
                        Button(t.label) { model.openOnSite(t.url) }.buttonStyle(.link).fontWeight(.semibold)
                        if let ids = t.postIds {
                            Text("\(ids.count) questions").foregroundStyle(.secondary)
                        } else {
                            Pill(t.change.map { "+\($0)%" } ?? "new", red: true)
                            Text("\(t.current) this week vs \(t.average ?? 0, specifier: "%.1f") a week before").foregroundStyle(.secondary)
                        }
                        Spacer()
                        if enabled {
                            Button(asking == t.key ? "Thinking…" : "Story Idea") { ask(t.key) }.disabled(asking != nil)
                        }
                    }
                    if let idea = ideas[t.key] {
                        Text(idea).textSelection(.enabled).padding(8).background(.quaternary, in: RoundedRectangle(cornerRadius: 6))
                    }
                }
                .padding(.vertical, 2)
            }
        }
    }

    func ask(_ key: String) {
        guard let api = model.api else { return }
        asking = key
        Task {
            defer { asking = nil }
            do {
                let result: StoryIdea = try await api.send("POST", "radar/idea", body: ["key": key])
                ideas[key] = result.idea ?? "Claude didn’t have an idea for this one."
            } catch {
                model.show(error.localizedDescription)
            }
        }
    }
}

struct TipsView: View {
    @Environment(AppModel.self) private var model
    @State private var status = "new"
    @State private var tips: [TipItem]?
    @State private var editors: [Editor] = []
    @State private var replies: [Int: String] = [:]

    static let statuses = [("new", "New"), ("following", "Following up"), ("used", "Used"), ("dismissed", "Dismissed")]

    var body: some View {
        Group {
            if let tips {
                if tips.isEmpty {
                    ContentUnavailableView("No tips here", systemImage: "envelope.open")
                } else {
                    List(tips) { tip in row(tip) }
                }
            } else {
                ProgressView().frame(maxWidth: .infinity, maxHeight: .infinity)
            }
        }
        .navigationTitle("Tips")
        .navigationSubtitle("Private to staff, never posted")
        .toolbar {
            Picker("Show", selection: $status) {
                ForEach(Self.statuses, id: \.0) { Text($0.1).tag($0.0) }
                Text("All").tag("all")
            }
        }
        .task(id: "\(status)|\(model.reloadToken)") {
            guard let api = model.api else { return }
            tips = try? await api.get("tips?status=\(status)")
            if editors.isEmpty { editors = (try? await api.get("editors")) ?? [] }
        }
    }

    @ViewBuilder
    func row(_ tip: TipItem) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack {
                Text("@\(tip.user.username)").fontWeight(.semibold)
                Text(ago(tip.createdAt)).foregroundStyle(.secondary)
                Pill(tip.contactOk ? "OK to contact" : "Don’t contact")
                ForEach(tip.flagged, id: \.self) { Pill($0, red: true) }
                if !tip.photos.isEmpty { Pill("\(tip.photos.count) photos") }
            }
            Text(tip.body).textSelection(.enabled)
            if let link = tip.link, let url = URL(string: link) {
                Link(link, destination: url).font(.callout)
            }
            if let about = tip.dispatch {
                Button("About: \(about.body)") { model.openOnSite(about.url) }.buttonStyle(.link).lineLimit(1)
            }
            HStack {
                Picker("Status", selection: Binding(get: { tip.status }, set: { new in
                    model.act("Updated.") { try await $0.post("tips/\(tip.id)", ["status": new]) }
                })) {
                    ForEach(Self.statuses, id: \.0) { Text($0.1).tag($0.0) }
                }
                .frame(maxWidth: 220)
                Picker("Assigned", selection: Binding(get: { tip.assignee?.id ?? 0 }, set: { new in
                    model.act("Assigned.") { try await $0.post("tips/\(tip.id)", ["assigned_to": new == 0 ? NSNull() : new]) }
                })) {
                    Text("Unassigned").tag(0)
                    ForEach(editors) { Text("@\($0.username)").tag($0.id) }
                }
                .frame(maxWidth: 240)
            }
            if tip.contactOk {
                HStack {
                    TextField("Reply in Stoop messages", text: Binding(get: { replies[tip.id] ?? "" }, set: { replies[tip.id] = $0 }))
                    Button("Send") {
                        let text = replies[tip.id] ?? ""
                        replies[tip.id] = nil
                        model.act("Sent to @\(tip.user.username).") { try await $0.post("tips/\(tip.id)/reply", ["body": text]) }
                    }
                    .disabled((replies[tip.id] ?? "").trimmingCharacters(in: .whitespaces).isEmpty)
                }
            }
        }
        .padding(.vertical, 4)
    }
}
