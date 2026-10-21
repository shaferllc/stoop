import SwiftUI

// Shapes returned by /api/admin. Keys arrive in snake_case and are converted.

struct Person: Decodable, Identifiable, Hashable {
    let id: Int
    let username: String
    let name: String?
    let url: String
    let isStaff: Bool
    let isBot: Bool
    var email: String?
    var verified: Bool?
    var createdAt: String?
    var dispatches: Int?
    var penalized: Bool?
}

struct Me: Decodable {
    struct Site: Decodable { let name: String; let url: String }
    struct Option: Decodable, Hashable { let value: String; let label: String }
    struct Line: Decodable { let name: String; let color: String }
    struct Options: Decodable {
        let ruleReasons: [String]
        let penalties: [Option]
        let lines: [String: Line]
    }

    let user: Person
    let site: Site
    let options: Options
}

struct Attention: Decodable {
    struct Item: Decodable, Identifiable {
        let key: String
        let label: String
        let count: Int
        let url: String
        var id: String { key }
    }

    let total: Int
    let items: [Item]

    func count(_ key: String) -> Int { items.first { $0.key == key }?.count ?? 0 }
}

struct Stats: Decodable {
    let accounts, newAccountsWeek, postersDay, dispatchesDay, weeklyPosters, scheduled, removedWeek, penaltiesWeek: Int
}

struct Screening: Decodable {
    var openai: [String: Double]?
    var claude: String?
    var autoRemoved: String?

    enum CodingKeys: String, CodingKey { case openai, claude, autoRemoved }

    // PHP sends an empty list as [], so read each part leniently.
    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        openai = try? c.decode([String: Double].self, forKey: .openai)
        claude = try? c.decode(String.self, forKey: .claude)
        autoRemoved = try? c.decode(String.self, forKey: .autoRemoved)
    }
}

struct Dispatch: Decodable, Identifiable {
    struct Image: Decodable, Hashable { let url: String; let alt: String? }
    struct Report: Decodable, Hashable { let reason: String; let note: String?; let by: String? }

    let id: Int
    let url: String
    let type: String
    let line: String?
    let stop: String?
    let stopName: String?
    let body: String
    let status: String
    let flaggedAt: String?
    let moderationReason: String?
    let screening: Screening?
    let createdAt: String?
    let user: Person
    let strikes: Int?
    let images: [Image]
    let reports: [Report]
}

struct Appeal: Decodable, Identifiable {
    struct Brief: Decodable { let type: String; let label: String; let reason: String }
    let id: Int
    let body: String
    let createdAt: String?
    let user: Person
    let penalty: Brief?
    let dispatch: Dispatch?
}

struct MessageReport: Decodable, Identifiable {
    struct Line: Decodable, Identifiable { let id: Int; let body: String; let user: Person?; let reported: Bool }
    let id: Int
    let reason: String
    let note: String?
    let createdAt: String?
    let reporter: Person
    let context: [Line]
}

struct Penalty: Decodable, Identifiable {
    let id: Int
    let type: String
    let label: String
    let reason: String
    let endsAt: String?
    let liftedAt: String?
    let createdAt: String?
    let user: Person?
}

struct UsersPage: Decodable {
    let data: [Person]
    let nextPage: Int?
}

struct PersonDetail: Decodable {
    struct Note: Decodable, Hashable { let body: String; let createdAt: String? }
    let person: Person
    let strikes: Int
    let penalties: [Penalty]
    let dispatches: [Dispatch]
    let notes: [Note]

    enum CodingKeys: String, CodingKey { case strikes, penalties, dispatches, notes }

    init(from decoder: Decoder) throws {
        person = try Person(from: decoder)
        let c = try decoder.container(keyedBy: CodingKeys.self)
        strikes = try c.decode(Int.self, forKey: .strikes)
        penalties = try c.decode([Penalty].self, forKey: .penalties)
        dispatches = try c.decode([Dispatch].self, forKey: .dispatches)
        notes = try c.decode([Note].self, forKey: .notes)
    }
}

struct Health: Decodable {
    struct Check: Decodable, Hashable { let name: String; let ok: Bool; let fix: String }
    struct Backup: Decodable, Hashable { let name: String; let bytes: Int }
    struct Task: Decodable, Hashable { let key: String; let label: String }
    let checks: [Check]
    let pendingMigrations: [String]
    let scheduleRanAt: String?
    let jobsWaiting: Int
    let jobsFailed: Int
    let databaseBytes: Int?
    let backups: [Backup]
    let php: String
    let laravel: String
    let environment: String
    let tasks: [Task]
}

struct FailedJob: Decodable, Identifiable {
    let uuid: String
    let name: String
    let failedAt: String?
    let error: String
    var id: String { uuid }
}

struct AuditRow: Decodable, Identifiable {
    let id: Int
    let action: String
    let subjectType: String?
    let subjectId: Int?
    let details: String?
    let createdAt: String?
    let actor: String?
}

struct LogText: Decodable { let log: String }

extension Color {
    init(hex: String) {
        let v = UInt64(hex.trimmingCharacters(in: CharacterSet(charactersIn: "#")), radix: 16) ?? 0
        self.init(red: Double((v >> 16) & 0xFF) / 255, green: Double((v >> 8) & 0xFF) / 255, blue: Double(v & 0xFF) / 255)
    }

    static let flag = Color(hex: "#c8102e")
}
