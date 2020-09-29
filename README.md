# BugYield

![](https://img.shields.io/circleci/project/github/reload/bug-yield/master.svg?style=for-the-badge)

BugYield is a console application which tracks changes to tickets
(issues, work items, bugs whatever) across systems (two-way) to avoid
tedious double registrations.

Which means that the developers only need to track time in Harvest,
and then ticket-titles will be transferred automatically from Jira to
the Harvest time entry AND time spend on that ticket will be
synchronized in Jira.

## Installation

Clone the repository and run composer install to install dependencies.
The PHP extensions curl, xml and mbstring are required.

## Configuration

BugYield needs to know where and how to access the systems involved.
This configuration is handled by a config.yml file. Copy the provided
config.sample.yml and update it with account information. If your
configuration file is not located in the root directory you can
specify the path to the config file using the `--config` option.

In order to send mails it needs a SendGrid key supplied in the
environment variable `SENDGRID_API_KEY`.

## Usage

BugYield currently supports two use cases:

1. *Time synchronization*: Mapping entries in Harvest to time elapsed in Jira tickets
2. *Title synchronization*: Mapping ticket titles from Jira to Harvest entries

BugYield works in the context of one or more Harvest projects
identified through their id, full name or code. Projects can be
specified in the configuration or using the
<code>--harvest-project</code> option. Use the magic name `all` to
specify all Harvest projects.

Run <code>./bugyield</code> from the command line to show all available commands.

1. **Time synchronization** example:
   `./bugyield tim --bugtracker=a-label` Run BugYield with the bugtracker defined in config.yml with the label `a-label`
2. **Title synchronization** example:
   `./bugyield tit --bugtracker=a-label` Run BugYield with the bugtracker defined in config.yml with the label `a-label`

Just change the `--bugtracker=XXXX` with another label to run another
bugtracker. We have two Jira bugtrackers configured in the same
config.yml

###  Running in docker

To build an image to run in docker, you must first build an image:

``` shell
docker build . -t reload/bug-yield:local
```

When running in docker, you should mount in the config file:

``` shell
docker run -v /path/to/config.yml:/bug-yield/config.yml reload/bug-yield:local
```

In development, you can mount in the full source and run a command
immediately:

``` shell
docker run -v $PWD:/bug-yield/ reload/bug-yield:local /bug-yield/bugyield --bugtracker=sometracker tim
```

### Time synchronization

BugYield can update tickets in Jira with time registrations in
Harvest. This makes it easier to show how much time has been spent on
a ticket and how this corresponds with estimates.

It works like this:

1. Add `#<ticket-id>` (without the <>'s) in the Harvest entry
   notes
2. Run the timesync command
3. The elapsed time field for the Jira ticket is updated and a new
   worklog is added to the ticket showing the entry id, the time
   spent, the task type and the notes from Harvest.

If the time or task for the Harvest entry is changed at a later point
in time, subsequent execution of the timesync command adds a new
comment to the ticket and the elapsed time field is adjusted
accordingly.

If a Harvest entry contains multiple ticket ids the time spent is
distributed evenly across the mentioned tickets.

If BugYield detects serious inconsistencies, then it will email the
offending user and optionally a separately defined email address (e.g.
to the Project Manager).

#### PRO TIP for JIRA

Make sure that the Closed state is editable in your Jira workflows,
thus enabling bugyield to update worklogs on closed issues - see [Jira
documentation](https://confluence.atlassian.com/display/JIRA/Allow+editing+of+Closed+Issues)

### Title synchronization

BugYield can update entries in Harvest with ticket titles from Jira.
This makes it easier register time on specific tickets without typing
other than the ticket-number (prefixed with a `#`). NOTE: When an
entry has been submitted and thereby locked, then we can't edit the
entry, and it will fail.

It works like this:

1. Add `<ticket-id>` (without the <>'s) in the Harvest entry notes
2. Run the titlesync command
3. The entry notes in Harvest are updated with the ticket titles from
   Jira replacing `<ticket-id>` with `<ticket-id>[<ticket-title>]`

If a Jira ticket title is changed at a later point in time, subsequent
execution of the titlesync command makes sure that the Harvest entry
notes are updated accordingly.

##  Known errors and problems

### Brackets in ticket titles

If you put brackets in your ticket title, you make it difficult for
BugYield to recognize our "codes". It will handle the ticket, but
display warnings and remove any comments on the Harvest entry created
by the user.
