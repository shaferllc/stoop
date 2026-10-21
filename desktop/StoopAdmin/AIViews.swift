import SwiftUI

// Moderator help from /api/admin/ai: Claude's suggestions, past decisions, person briefs,
// appeal notes, threshold insights and watch phrases. Claude only suggests; people decide.

struct Suggestion: Decodable, Hashable {
    let explanation: String
    let rule: String?
    let action: String
    let penalty: String
    let confidence: String
}

struct PastDecisions: Decodable, Hashable {
    struct Example: Decodable, Hashable { let id: Int; let body: String; let status: String; let reason: String? }
    let approved: Int
    let removed: Int
    let examples: [Example]
}

struct QueueExtras: Decodable {
    struct Item: Decodable {
        let id: Int
        let suggestion: Suggestion?
        let past: PastDecisions
        let blur: Bool
        let heldBecause: [String]
    }
    let ai: Bool
    let items: [Item]
}

struct ThresholdRow: Decodable, Identifiable {
    let category: String
    let threshold: Double
    let held, approved, removed: Int
    let approvalRate: Double?
    let suggested: Double?
    let advice: String
    var id: String { category }
}

struct ThresholdInsights: Decodable { let days: Int; let categories: [ThresholdRow] }

struct WatchPhrase: Decodable, Identifiable, Hashable {
    let id: Int
    let phrase: String
    let meaning: String
    let severity: String
    let active: Bool
}

/// The queue's extras, loaded in one call. Missing suggestions are asked for one card at a
/// time, and only when the site has an Anthropic key.
@MainActor @Observable
final class QueueAI {
    var enabled = false
    /// Until the extras arrive, every card stays blurred.
    var loaded = false
    var items: [Int: QueueExtras.Item] = [:]
    var suggestions: [Int: Suggestion] = [:]
    private var asked: Set<Int> = []

    func load(_ api: API?) async {
        defer { loaded = true }
        guard let api, let extras: QueueExtras = try? await api.get("ai/queue") else { return }
        enabled = extras.ai
        items = Dictionary(extras.items.map { ($0.id, $0) }, uniquingKeysWith: { a, _ in a })
        for item in extras.items { if let s = item.suggestion { suggestions[item.id] = s } }
    }

    func suggest(_ id: Int, _ api: API?) async {
        guard enabled, suggestions[id] == nil, !asked.contains(id), let api else { return }
        asked.insert(id)
        struct Reply: Decodable { let suggestion: Suggestion? }
        if let reply: Reply = try? await api.send("POST", "ai/dispatches/\(id)/suggest", body: [:]), let s = reply.suggestion {
            suggestions[id] = s
        } else {
            asked.remove(id) // try again on the next load
        }
    }
}

func penaltyLabel(_ value: String, _ me: Me?) -> String {
    me?.options.penalties.first { $0.value == value }?.label ?? value
}

/// Under each queue card: why it was held, Claude's suggestion and past decisions.
struct QueueAssistView: View {
    @Environment(AppModel.self) private var model
    let id: Int
    let ai: QueueAI
    let accept: () -> Void

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            if let item = ai.items[id] {
                ForEach(item.heldBecause, id: \.self) { Label($0, systemImage: "eye.trianglebadge.exclamationmark").foregroundStyle(Color.flag) }
            }
            if let s = ai.suggestions[id] {
                HStack(alignment: .firstTextBaseline) {
                    Image(systemName: "sparkles").foregroundStyle(.secondary)
                    VStack(alignment: .leading, spacing: 2) {
                        HStack {
                            Text(s.action == "approve" ? "Claude suggests approving" : "Claude suggests removing" + (s.rule.map { ": \($0)" } ?? "")
                                 + (s.penalty == "none" ? "" : " · " + penaltyLabel(s.penalty, model.me))).fontWeight(.semibold)
                            Pill("\(s.confidence) confidence", red: s.action == "remove" && s.confidence == "high")
                        }
                        Text(s.explanation).foregroundStyle(.secondary)
                    }
                    Spacer()
                    Button("Accept Suggestion", action: accept).help("S")
                }
            }
            if let past = ai.items[id]?.past {
                if past.approved + past.removed == 0 {
                    Text("No similar posts decided before.").foregroundStyle(.secondary)
                } else {
                    DisclosureGroup("Similar past posts: \(past.approved) approved, \(past.removed) removed") {
                        ForEach(past.examples, id: \.self) { e in
                            HStack(alignment: .firstTextBaseline) {
                                Pill(e.status, red: e.status == "removed")
                                Text(e.body + (e.reason.map { " · \($0)" } ?? "")).lineLimit(2)
                            }
                        }
                    }
                }
            }
        }
        .font(.callout)
        .task(id: ai.enabled) { await ai.suggest(id, model.api) }
    }
}

/// A small panel that loads one path and shows what came back, for briefs and appeal notes.
struct AssistPanel<T: Decodable, Content: View>: View {
    @Environment(AppModel.self) private var model
    let path: String
    @ViewBuilder let content: (T) -> Content
    @State private var value: T?

    var body: some View {
        Group {
            if let value { content(value) } else { ProgressView().controlSize(.small) }
        }
        .task(id: path) { value = try? await model.api?.get(path) }
    }
}

struct PersonBriefView: View {
    let id: Int
    struct Brief: Decodable { let ai: Bool; let text: String }

    var body: some View {
        GroupBox("Brief") {
            AssistPanel(path: "ai/users/\(id)/brief") { (b: Brief) in
                VStack(alignment: .leading, spacing: 4) {
                    Text(b.text).textSelection(.enabled)
                    Text(b.ai ? "Written by Claude from their record and posts; refreshed hourly." : "Plain facts. A written brief on tone needs ANTHROPIC_API_KEY.")
                        .font(.caption).foregroundStyle(.secondary)
                }
                .frame(maxWidth: .infinity, alignment: .leading)
            }
            .padding(6)
        }
    }
}

struct AppealAssistView: View {
    let id: Int
    struct Notes: Decodable { let summary: String; let supports: [String]; let contradicts: [String] }
    struct Reply: Decodable { let ai: Bool; let notes: Notes? }

    var body: some View {
        AssistPanel(path: "ai/appeals/\(id)") { (r: Reply) in
            if let n = r.notes {
                VStack(alignment: .leading, spacing: 4) {
                    Label(n.summary, systemImage: "sparkles").fontWeight(.semibold)
                    ForEach(n.supports, id: \.self) { Label($0, systemImage: "checkmark.circle").foregroundStyle(.green) }
                    ForEach(n.contradicts, id: \.self) { Label($0, systemImage: "xmark.circle").foregroundStyle(Color.flag) }
                }
            } else {
                Text(r.ai ? "Claude couldn’t look at this appeal just now." : "The appeal helper needs ANTHROPIC_API_KEY.").foregroundStyle(.secondary)
            }
        }
        .font(.callout)
    }
}

/// Threshold tuning (read-only) and the watch-phrase list.
struct InsightsView: View {
    @Environment(AppModel.self) private var model
    @State private var editing: WatchPhrase?
    @State private var adding = false

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 14) {
                Text("OpenAI thresholds, last 30 days").font(.title2.bold())
                Text("Held posts moderators approved or removed, by category. Suggestions follow simple rules; change thresholds in config/stoop.php.")
                    .foregroundStyle(.secondary)
                Loaded(path: "ai/insights") { (i: ThresholdInsights) in
                    Grid(alignment: .leading, horizontalSpacing: 16, verticalSpacing: 6) {
                        GridRow {
                            ForEach(["Category", "Threshold", "Approved", "Removed", "Suggested", "Why"], id: \.self) { Text($0).foregroundStyle(.secondary) }
                        }
                        Divider()
                        ForEach(i.categories) { t in
                            GridRow {
                                Text(t.category).monospaced()
                                Text(t.threshold, format: .number.precision(.fractionLength(2)))
                                Text("\(t.approved)" + (t.approvalRate.map { " (\(Int($0 * 100))%)" } ?? ""))
                                Text("\(t.removed)")
                                Text(t.suggested.map { String(format: "%.2f", $0) } ?? "—").fontWeight(t.suggested == nil ? .regular : .bold)
                                    .foregroundStyle(t.suggested == nil ? Color.primary : Color.flag)
                                Text(t.advice).foregroundStyle(.secondary)
                            }
                        }
                    }
                }

                HStack {
                    Text("Watch phrases").font(.title2.bold())
                    Spacer()
                    Button("Add Phrase…") { adding = true }
                }
                Text("Coded and local phrases. New posts with an active phrase are held, and moderators see the meaning.").foregroundStyle(.secondary)
                Loaded(path: "ai/watch-phrases") { (phrases: [WatchPhrase]) in
                    VStack(alignment: .leading, spacing: 0) {
                        ForEach(phrases) { p in
                            HStack(alignment: .firstTextBaseline) {
                                VStack(alignment: .leading, spacing: 2) {
                                    HStack {
                                        Text("“\(p.phrase)”").fontWeight(.semibold).strikethrough(!p.active)
                                        Pill(p.severity, red: p.severity == "high")
                                    }
                                    Text(p.meaning).foregroundStyle(.secondary)
                                }
                                Spacer()
                                Button("Edit…") { editing = p }
                                Button(p.active ? "Turn Off" : "Turn On") { save(p, active: !p.active) }
                                Button("Delete", role: .destructive) {
                                    model.act("Deleted.") { try await $0.delete("ai/watch-phrases/\(p.id)") }
                                }
                            }
                            .padding(.vertical, 6)
                            Divider()
                        }
                    }
                }
            }
            .padding(24)
            .frame(maxWidth: .infinity, alignment: .leading)
        }
        .navigationTitle("Insights")
        .sheet(item: $editing) { WatchPhraseSheet(phrase: $0) }
        .sheet(isPresented: $adding) { WatchPhraseSheet(phrase: nil) }
    }

    func save(_ p: WatchPhrase, active: Bool) {
        let body: [String: Any] = ["phrase": p.phrase, "meaning": p.meaning, "severity": p.severity, "active": active]
        model.act(active ? "Turned on." : "Turned off.") { let _: Done = try await $0.send("PUT", "ai/watch-phrases/\(p.id)", body: body) }
    }
}

struct WatchPhraseSheet: View {
    @Environment(AppModel.self) private var model
    @Environment(\.dismiss) private var dismiss
    let phrase: WatchPhrase?
    @State private var text = ""
    @State private var meaning = ""
    @State private var severity = "medium"

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text(phrase == nil ? "Add a watch phrase" : "Edit watch phrase").font(.title2.bold())
            Form {
                TextField("Phrase", text: $text)
                TextField("What it means, for moderators", text: $meaning, axis: .vertical)
                Picker("Severity", selection: $severity) {
                    ForEach(["low", "medium", "high"], id: \.self) { Text($0.capitalized).tag($0) }
                }
            }
            .formStyle(.grouped)
            HStack {
                Spacer()
                Button("Cancel", role: .cancel) { dismiss() }.keyboardShortcut(.cancelAction)
                Button("Save") {
                    let body: [String: Any] = ["phrase": text, "meaning": meaning, "severity": severity, "active": phrase?.active ?? true]
                    let path = phrase.map { "ai/watch-phrases/\($0.id)" } ?? "ai/watch-phrases"
                    model.act("Saved.") { [method = phrase == nil ? "POST" : "PUT"] in let _: Done = try await $0.send(method, path, body: body) }
                    dismiss()
                }
                .keyboardShortcut(.defaultAction)
                .disabled(text.count < 3 || meaning.isEmpty)
            }
        }
        .padding(24)
        .frame(width: 480)
        .onAppear {
            text = phrase?.phrase ?? ""
            meaning = phrase?.meaning ?? ""
            severity = phrase?.severity ?? "medium"
        }
    }
}
