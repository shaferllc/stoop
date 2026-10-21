import SwiftUI

// @sentinel's hourly moderation summaries on the Overview: GET /api/admin/sentinel (moderators).

struct SentinelFeed: Decodable {
    struct Summary: Decodable, Identifiable {
        let hour: String
        let text: String
        let ai: Bool
        var id: String { hour }
    }
    let claude: Bool
    let summaries: [Summary]
}

struct SentinelPanel: View {
    var body: some View {
        Loaded(path: "sentinel") { (feed: SentinelFeed) in
            VStack(alignment: .leading, spacing: 10) {
                Text("Sentinel").font(.title2.bold())
                if !feed.claude {
                    Text("Plain summaries. Needs ANTHROPIC_API_KEY for Claude.").font(.callout).foregroundStyle(.secondary)
                }
                GroupBox {
                    if feed.summaries.isEmpty {
                        Text("Nothing to report yet.").foregroundStyle(.secondary).frame(maxWidth: .infinity, alignment: .leading).padding(4)
                    } else {
                        VStack(alignment: .leading, spacing: 8) {
                            ForEach(feed.summaries.prefix(5)) { summary in
                                VStack(alignment: .leading, spacing: 2) {
                                    Text(Self.time(summary.hour)).font(.caption).foregroundStyle(.secondary)
                                    Text(summary.text)
                                }
                            }
                        }
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .padding(4)
                    }
                }
            }
        }
    }

    static func time(_ iso: String) -> String {
        guard let date = try? Date(iso, strategy: .iso8601) else { return iso }
        return date.formatted(date: .abbreviated, time: .shortened)
    }
}
